import { ButtplugClient } from '@zendrex/buttplug.js';
import { spawn } from 'node:child_process';
import { appendFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

const baseUrl = requiredEnv('RELAY_BASE_URL').replace(/\/$/, '');
const agentToken = requiredEnv('RELAY_AGENT_TOKEN');
const intifaceUrl = process.env.INTIFACE_URL || 'ws://127.0.0.1:12345/buttplug';
const pollInterval = boundedNumber(process.env.POLL_INTERVAL_MS, 2000, 500, 30_000);
const vibrateLevel = boundedNumber(process.env.VIBRATE_LEVEL, 1, 0.01, 1);
const maxDuration = boundedNumber(process.env.PREMIUM_MAX_DURATION, 60, 20, 300);
const userAgent = process.env.RELAY_USER_AGENT || 'fuckme-relay-agent/1.0';
const audioEnabled = (process.env.AUDIO_ENABLED || 'true').toLowerCase() === 'true';
const audioInput = process.env.AUDIO_INPUT || 'default';
const logFile = resolve(process.env.RELAY_LOG_FILE || './logs/relay.log');
const notificationsEnabled = (process.env.DESKTOP_NOTIFICATIONS || 'true').toLowerCase() === 'true';
const intifaceRetries = boundedNumber(process.env.INTIFACE_RETRIES, 3, 1, 10);
const reconnectInterval = boundedNumber(process.env.INTIFACE_RECONNECT_INTERVAL_MS, 8000, 2000, 60000);

if (!baseUrl.startsWith('https://') || !/^wss?:\/\/(127\.0\.0\.1|localhost|\[::1\])(?::\d+)?\//.test(intifaceUrl)) {
  throw new Error('RELAY_BASE_URL must use HTTPS and INTIFACE_URL must point to loopback.');
}
if (agentToken.length < 32) throw new Error('RELAY_AGENT_TOKEN must contain at least 32 characters.');

let stopping = false;
let activeDevice = null;
let heartbeatOnline = null;
let heartbeatAt = 0;

mkdirSync(dirname(logFile), { recursive: true });

function localLog(message, level = 'info') {
  const line = `[${new Date().toLocaleString()}] [${level.toUpperCase()}] ${message}`;
  if (level === 'error') console.error(line);
  else console.log(line);
  try { appendFileSync(logFile, `${line}\n`, { encoding: 'utf8', mode: 0o600 }); }
  catch (error) { console.error(`[agent] Could not write local log: ${error.message}`); }
}

function notifyTrigger(nickname, duration, intensity) {
  if (!notificationsEnabled) return;
  const sender = nickname || 'Anonymous';
  const notification = spawn('notify-send', ['--app-name=Pulse Control', '--urgency=normal', 'Incoming vibration', `${sender} sent ${duration}s at ${intensity}%`], { stdio: 'ignore' });
  notification.on('error', (error) => localLog(`Desktop notification failed: ${error.message}`, 'error'));
}

function requiredEnv(name) {
  const value = process.env[name]?.trim();
  if (!value) throw new Error(`${name} is required.`);
  return value;
}

function boundedNumber(value, fallback, minimum, maximum) {
  const number = value === undefined ? fallback : Number(value);
  if (!Number.isFinite(number) || number < minimum || number > maximum) {
    throw new Error(`Numeric configuration must be between ${minimum} and ${maximum}.`);
  }
  return number;
}

const sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

async function api(path, body = {}) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 10_000);
  try {
    const response = await fetch(`${baseUrl}${path}`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${agentToken}`,
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'User-Agent': userAgent,
      },
      body: JSON.stringify(body),
      signal: controller.signal,
    });
    const text = await response.text();
    let result;
    try {
      result = JSON.parse(text);
    } catch {
      const body = text.replace(/\s+/g, ' ').trim().slice(0, 160) || '(empty response)';
      throw new Error(`Relay API returned HTTP ${response.status}, non-JSON response: ${body}`);
    }
    if (!response.ok || result.ok !== true) throw new Error(`Relay API returned HTTP ${response.status}.`);
    return result;
  } finally {
    clearTimeout(timeout);
  }
}

async function recordReaction() {
  if (!audioEnabled) return null;
  return new Promise((resolve, reject) => {
    const chunks = [];
    const ffmpeg = spawn('ffmpeg', ['-hide_banner', '-loglevel', 'info', '-f', 'pulse', '-i', audioInput, '-t', '3', '-af', 'highpass=f=100,lowpass=f=4000,silencedetect=noise=-32dB:d=0.25', '-ac', '1', '-ar', '48000', '-c:a', 'libopus', '-b:a', '48k', '-f', 'webm', 'pipe:1'], { stdio: ['ignore', 'pipe', 'pipe'] });
    let errorText = '';
    ffmpeg.stdout.on('data', (chunk) => chunks.push(chunk));
    ffmpeg.stderr.on('data', (chunk) => { errorText += chunk.toString(); });
    ffmpeg.on('error', reject);
    ffmpeg.on('close', (code) => {
      if (code !== 0) return reject(new Error(errorText.trim() || `ffmpeg exited with ${code}`));
      const starts = [...errorText.matchAll(/silence_start: ([0-9.]+)/g)].map((match) => Number(match[1]));
      const ends = [...errorText.matchAll(/silence_end: ([0-9.]+)/g)].map((match) => Number(match[1]));
      const hasVoiceActivity = starts.length === 0 || ends.some((end, index) => end - (starts[index] ?? 0) < 2.75);
      resolve(hasVoiceActivity ? Buffer.concat(chunks) : null);
    });
  });
}

async function uploadReaction(jobId, audio) {
  if (!audio?.length) return;
  const response = await fetch(`${baseUrl}/agent/audio.php?id=${encodeURIComponent(jobId)}`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${agentToken}`, 'Content-Type': 'audio/webm', 'User-Agent': userAgent },
    body: audio,
  });
  if (!response.ok) throw new Error(`Audio upload returned HTTP ${response.status}.`);
}

async function connectDevice() {
  let lastError;
  for (let attempt = 1; attempt <= intifaceRetries && !stopping; attempt += 1) {
    const client = new ButtplugClient(intifaceUrl, { clientName: 'Private relay agent', autoReconnect: true, reconnectDelay: 500, maxReconnectDelay: 3000, maxReconnectAttempts: 5 });
    try {
      await client.connect();
      await client.requestDeviceList();
      let device = client.devices.find((candidate) => candidate.canOutput('Vibrate'));
      if (device) return { client, device };
      await client.startScanning();
      const deadline = Date.now() + 12_000;
      while (!stopping && Date.now() < deadline) {
        await client.requestDeviceList().catch(() => {});
        device = client.devices.find((candidate) => candidate.canOutput('Vibrate'));
        if (device) {
          await client.stopScanning().catch(() => {});
          return { client, device };
        }
        await sleep(500);
      }
      lastError = new Error('No device with a Vibrate actuator is available.');
    } catch (error) {
      lastError = error;
      localLog(`Intiface connection attempt ${attempt}/${intifaceRetries} failed: ${error.message}`, 'error');
    } finally {
      await client.stopScanning().catch(() => {});
      await client.disconnect().catch(() => {});
    }
    if (attempt < intifaceRetries) await sleep(attempt * 1500);
  }
  throw lastError || new Error('Intiface is unavailable.');
}

async function probeDevice() {
  let client;
  try {
    client = new ButtplugClient(intifaceUrl, { clientName: 'Private relay heartbeat', autoReconnect: true, reconnectDelay: 500, maxReconnectDelay: 2000, maxReconnectAttempts: 2 });
    await client.connect();
    await client.requestDeviceList();
    if (client.devices.some((device) => device.canOutput('Vibrate'))) return true;
    await client.startScanning();
    const deadline = Date.now() + 4000;
    while (!stopping && Date.now() < deadline) {
      await client.requestDeviceList().catch(() => {});
      if (client.devices.some((device) => device.canOutput('Vibrate'))) return true;
      await sleep(500);
    }
    return false;
  } catch {
    return false;
  } finally {
    if (client) await client.disconnect().catch(() => {});
  }
}

async function sendHeartbeat() {
  const online = activeDevice ? true : await probeDevice();
  const now = Date.now();
  if (online !== heartbeatOnline || now - heartbeatAt >= 8000) {
    await api('/agent/heartbeat.php', { online });
    heartbeatOnline = online;
    heartbeatAt = now;
    localLog(`Device is ${online ? 'online' : 'offline / charging'}.`);
  }
}

async function execute(job) {
  let client;
  let success = false;
  try {
    if (!job || !/^[a-f0-9]{32}$/.test(job.id) || !Number.isInteger(job.duration) || job.duration < 5 || job.duration > maxDuration || !Number.isInteger(job.intensity) || job.intensity < 1 || job.intensity > 100 || typeof job.nickname !== 'string' || [...job.nickname].length > 32 || !Array.isArray(job.pattern) || job.pattern.length < 2 || job.pattern.length > 101) {
      throw new Error('The relay returned an invalid job.');
    }
    const connection = await connectDevice();
    client = connection.client;
    activeDevice = connection.device;
    const pattern = job.pattern.map((point) => ({ t: Number(point.t), v: Number(point.v) }));
    if (pattern.some((point, index) => !Number.isFinite(point.t) || !Number.isFinite(point.v) || point.t < 0 || point.t > 1 || point.v < 0 || point.v > 1 || (index > 0 && point.t <= pattern[index - 1].t))) throw new Error('The relay returned an invalid pattern.');
    const startedAt = Date.now();
    const nickname = job.nickname.trim() || 'Anonymous';
    localLog(`Trigger from ${nickname}: ${job.intensity}% for ${job.duration}s.`);
    notifyTrigger(job.nickname.trim(), job.duration, job.intensity);
    const recording = recordReaction()
      .then((audio) => uploadReaction(job.id, audio))
      .then(() => { if (audioEnabled) localLog('3-second reaction uploaded.'); })
      .catch((error) => localLog(`Audio failed: ${error.message}`, 'error'));
    for (let index = 0; index < pattern.length; index += 1) {
      const point = pattern[index];
      await sleep(Math.max(0, (point.t * job.duration * 1000) - (index ? pattern[index - 1].t * job.duration * 1000 : 0)));
      await activeDevice.vibrate(point.v);
    }
    await recording;
    localLog(`Vibration completed after ${(Date.now() - startedAt) / 1000}s.`);
    success = true;
  } catch (error) {
    localLog(`Job failed: ${error.message}`, 'error');
  } finally {
    if (activeDevice) await activeDevice.stop().catch((error) => localLog(`Safe stop failed: ${error.message}`, 'error'));
    activeDevice = null;
    if (client) await client.disconnect().catch(() => {});
    await api('/agent/complete.php', { id: job.id, success }).catch((error) => localLog(`Completion failed: ${error.message}`, 'error'));
  }
}

async function shutdown(signal) {
  if (stopping) return;
  stopping = true;
  localLog(`${signal}; stopping safely.`);
  if (activeDevice) await activeDevice.stop().catch(() => {});
}

process.on('SIGINT', () => shutdown('SIGINT'));
process.on('SIGTERM', () => shutdown('SIGTERM'));

localLog('Started. No inbound network listener is used.');
const heartbeatTimer = setInterval(() => {
  if (!stopping) sendHeartbeat().catch((error) => localLog(`Heartbeat failed: ${error.message}`, 'error'));
}, reconnectInterval);
while (!stopping) {
  try {
    await sendHeartbeat();
    const { job } = await api('/agent/poll.php');
    if (job) await execute(job);
    else await sleep(pollInterval);
  } catch (error) {
    localLog(`Poll failed: ${error.message}`, 'error');
    await sleep(Math.max(pollInterval, 5000));
  }
}
clearInterval(heartbeatTimer);
