import './styles.css';

const elements = {
  slider: document.querySelector('#duration'),
  duration: document.querySelector('#duration-output strong'),
  intensity: document.querySelector('#intensity'),
  intensityOutput: document.querySelector('#intensity-output strong'),
  intensityLimit: document.querySelector('#intensity-limit'),
  trigger: document.querySelector('#trigger-button'),
  buttonLabel: document.querySelector('#button-label'),
  statusPill: document.querySelector('#status-pill'),
  statusLabel: document.querySelector('#status-label'),
  feedback: document.querySelector('#feedback'),
  feedbackText: document.querySelector('#feedback-text'),
  updated: document.querySelector('#last-updated'),
  captcha: document.querySelector('#captcha'),
  premiumBuy: document.querySelector('#premium-buy'),
  invoice: document.querySelector('#invoice-id'),
  unlock: document.querySelector('#unlock-premium'),
  reaction: document.querySelector('#reaction'),
  reactionAudio: document.querySelector('#reaction-audio'),
  nickname: document.querySelector('#nickname'),
  offlineScreen: document.querySelector('#offline-screen'),
  toast: document.querySelector('#toast'),
  modeButtons: [...document.querySelectorAll('.mode-button')],
  drawPanel: document.querySelector('#draw-panel'),
  presetPanel: document.querySelector('#preset-panel'),
  canvas: document.querySelector('#waveform'),
  clearWaveform: document.querySelector('#clear-waveform'),
  preset: document.querySelector('#preset'),
  waveformTitle: document.querySelector('#waveform-title'),
};

const state = {
  captchaToken: '',
  captchaId: null,
  submitting: false,
  syncing: true,
  busyUntil: 0,
  cooldownUntil: 0,
  deviceOnline: false,
  lastStatusError: false,
  feedbackLockUntil: 0,
  premium: false,
  premiumMaxDuration: 20,
  mode: 'direct',
  points: [],
};

const WAVE_KEY = 'pulse-control:last-waveform';
const defaultPoints = () => Array.from({ length: 25 }, (_, index) => ({ t: index / 24, v: 0.8 }));
function loadWaveform() { try { const value = JSON.parse(localStorage.getItem(WAVE_KEY)); return Array.isArray(value) && value.length >= 2 ? value : defaultPoints(); } catch { return defaultPoints(); } }
function saveWaveform() { localStorage.setItem(WAVE_KEY, JSON.stringify(state.points)); }
function presetPoints(name) {
  return Array.from({ length: 25 }, (_, i) => { const t = i / 24; const v = name === 'pulse' ? (i % 6 < 3 ? 1 : 0.12) : name === 'wave' ? 0.5 + Math.sin(t * Math.PI * 4) * 0.45 : name === 'ramp' ? t : 0.8; return { t, v: Math.max(0, Math.min(1, v)) }; });
}
function waveformPoints() {
  if (state.mode === 'direct') return [{ t: 0, v: Number(elements.intensity.value) / 100 }, { t: 1, v: Number(elements.intensity.value) / 100 }];
  const points = state.points;
  const limit = state.premium ? 100 : Math.max(40, Math.round(100 - ((Number(elements.slider.value) - 5) / 15) * 60));
  return points.map((point) => ({ ...point, v: Math.min(point.v, limit / 100) }));
}
function drawWaveform() {
  const canvas = elements.canvas; const ctx = canvas.getContext('2d'); const width = canvas.clientWidth || canvas.width; const height = canvas.height; canvas.width = Math.max(320, Math.floor(width * devicePixelRatio)); canvas.height = Math.floor(260 * devicePixelRatio); ctx.scale(devicePixelRatio, devicePixelRatio); const w = canvas.width / devicePixelRatio; const h = 260; ctx.clearRect(0, 0, w, h); ctx.strokeStyle = '#d2cec4'; ctx.lineWidth = 1; for (let i = 0; i <= 10; i++) { const y = 18 + (h - 38) * i / 10; ctx.beginPath(); ctx.moveTo(38, y); ctx.lineTo(w - 12, y); ctx.stroke(); ctx.fillStyle = '#716f68'; ctx.font = '10px DM Mono'; ctx.fillText(`${100 - i * 10}`, 4, y + 3); } ctx.beginPath(); waveformPoints().forEach((p, i) => { const x = 38 + p.t * (w - 50); const y = 18 + (1 - p.v) * (h - 38); i ? ctx.lineTo(x, y) : ctx.moveTo(x, y); }); ctx.strokeStyle = '#171714'; ctx.lineWidth = 3; ctx.stroke(); }
function setMode(mode, reload = false) {
  const changed = state.mode !== mode;
  state.mode = mode;
  if (mode === 'draw' && (changed || reload)) state.points = loadWaveform();
  if (mode === 'preset' && (changed || reload)) state.points = presetPoints(elements.preset.value);
  elements.modeButtons.forEach((button) => button.classList.toggle('is-active', button.dataset.mode === mode));
  elements.drawPanel.hidden = mode === 'direct';
  elements.presetPanel.hidden = mode !== 'preset';
  elements.waveformTitle.textContent = mode === 'preset' ? 'Edit this preset' : 'Draw a waveform';
  drawWaveform();
}

const siteKey = import.meta.env.VITE_HCAPTCHA_SITE_KEY;

function secondsRemaining(until) {
  return Math.max(0, Math.ceil((until - Date.now()) / 1000));
}

function formatTime(totalSeconds) {
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

function setFeedback(message, type = 'info', lockFor = 0) {
  elements.feedback.dataset.type = type;
  elements.feedback.querySelector('.feedback-icon').textContent = type === 'success' ? '✓' : type === 'error' ? '!' : 'i';
  elements.feedbackText.textContent = message;
  state.feedbackLockUntil = lockFor ? Date.now() + lockFor : state.feedbackLockUntil;
}

let toastTimer;
function showToast(message) {
  elements.toast.textContent = message;
  elements.toast.hidden = false;
  window.clearTimeout(toastTimer);
  toastTimer = window.setTimeout(() => { elements.toast.hidden = true; }, 4500);
}

function updateInterface() {
  const busy = secondsRemaining(state.busyUntil);
  const cooldown = secondsRemaining(state.cooldownUntil);
  document.body.classList.toggle('device-offline', !state.deviceOnline);
  elements.offlineScreen.setAttribute('aria-hidden', state.deviceOnline ? 'true' : 'false');

  elements.statusPill.dataset.state = state.lastStatusError ? 'unknown' : !state.deviceOnline ? 'offline' : busy ? 'busy' : 'available';
  elements.statusLabel.textContent = state.lastStatusError
    ? 'Status unavailable'
    : !state.deviceOnline
      ? 'Charging · try later'
    : busy
      ? `Busy · ${formatTime(busy)}`
      : 'Available';

  let buttonText = 'Send trigger';
  if (state.submitting) buttonText = 'Sending…';
  else if (state.syncing) buttonText = 'Checking status';
  else if (!state.deviceOnline) buttonText = 'Device is charging';
  else if (busy) buttonText = `Busy · ${formatTime(busy)}`;
  else if (cooldown) buttonText = `Ready in ${formatTime(cooldown)}`;
  else if (!state.captchaToken) buttonText = 'Complete verification';

  elements.buttonLabel.textContent = buttonText;
  elements.trigger.disabled = state.submitting || state.syncing || !state.deviceOnline || busy > 0 || cooldown > 0 || !state.captchaToken;
  elements.slider.max = String(state.premium ? state.premiumMaxDuration : 20);
  if (Number(elements.slider.value) > Number(elements.slider.max)) elements.slider.value = elements.slider.max;
  updatePowerLimit();

  if (!state.submitting && !state.syncing && Date.now() >= state.feedbackLockUntil && !state.deviceOnline) {
    setFeedback('The device is currently charging. Please try again later.', 'info');
  } else if (!state.submitting && !state.syncing && Date.now() >= state.feedbackLockUntil && cooldown) {
    setFeedback(`Cooldown active. Another request can be sent in ${formatTime(cooldown)}.`, 'info');
  } else if (!state.submitting && !state.syncing && Date.now() >= state.feedbackLockUntil && busy) {
    setFeedback(`A trigger is active for another ${formatTime(busy)}.`, 'info');
  }
}

function updatePowerLimit() {
  const duration = Number(elements.slider.value);
  const maximum = state.premium ? 100 : Math.max(40, Math.round(100 - ((duration - 5) / 15) * 60));
  elements.intensity.max = String(maximum);
  if (Number(elements.intensity.value) > maximum) {
    elements.intensity.value = String(maximum);
    showToast(`Strength was limited to ${maximum}% for ${duration} seconds.`);
  }
  elements.intensityOutput.textContent = elements.intensity.value;
  elements.intensityLimit.textContent = state.premium ? 'Maximum: 100% · Premium' : `Maximum: ${maximum}% at ${duration} seconds`;
  elements.intensity.style.setProperty('--range-progress', `${((Number(elements.intensity.value) - 1) / Math.max(1, maximum - 1)) * 100}%`);
}

function applyTimers(data) {
  const receivedAt = Date.now();
  state.busyUntil = receivedAt + Math.max(0, Number(data.busyRemaining) || 0) * 1000;
  state.cooldownUntil = receivedAt + Math.max(0, Number(data.cooldownRemaining) || 0) * 1000;
}

async function refreshStatus({ quiet = false } = {}) {
  if (!quiet) state.syncing = true;
  updateInterface();

  try {
    const response = await fetch('/api/status.php', {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.message || 'Status request failed.');

    applyTimers(data);
    state.deviceOnline = data.deviceOnline === true;
    state.lastStatusError = false;
    elements.updated.textContent = `Synchronized ${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
    if (!quiet && !secondsRemaining(state.busyUntil) && !secondsRemaining(state.cooldownUntil)) {
      setFeedback('The service is available. Complete verification to continue.', 'info');
    }
  } catch (error) {
    state.lastStatusError = true;
    if (!quiet) setFeedback('Unable to check availability. We will try again shortly.', 'error');
  } finally {
    state.syncing = false;
    updateInterface();
  }
}

function resetCaptcha() {
  state.captchaToken = '';
  if (window.hcaptcha && state.captchaId !== null) window.hcaptcha.reset(state.captchaId);
}

async function waitForReaction(token) {
  for (let attempt = 0; attempt < 30; attempt += 1) {
    await new Promise((resolve) => window.setTimeout(resolve, 2000));
    try {
      const response = await fetch(`/api/audio.php?token=${encodeURIComponent(token)}`, { credentials: 'same-origin', headers: { Accept: 'audio/webm, application/json' } });
      if (response.status === 202) continue;
      if (!response.ok) break;
      const blob = await response.blob();
      if (!blob.type.startsWith('audio/')) break;
      if (elements.reactionAudio.src) URL.revokeObjectURL(elements.reactionAudio.src);
      elements.reactionAudio.src = URL.createObjectURL(blob);
      elements.reaction.hidden = false;
      setFeedback('Your private 3-second reaction is ready. It expires from the server in 5 minutes.', 'success', 8000);
      return;
    } catch { /* Retry while the agent records and uploads. */ }
  }
}

async function sendTrigger() {
  if (state.submitting || elements.trigger.disabled) return;
  state.submitting = true;
  const captchaToken = state.captchaToken;
  updateInterface();
  setFeedback('Sending your secure trigger…', 'info');

  try {
    const response = await fetch('/api/trigger.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ duration: Number(elements.slider.value), intensity: Number(elements.intensity.value), nickname: elements.nickname.value.trim(), premium: state.premium, mode: state.mode, pattern: waveformPoints(), captchaToken }),
    });
    const data = await response.json();

    if (!response.ok || data.ok !== true) {
      const error = new Error(data.message || 'The trigger could not be sent.');
      error.retryAfter = Number(data.retryAfter) || 0;
      throw error;
    }

    applyTimers(data);
    state.premium = false;
    state.premiumMaxDuration = 20;
    setFeedback(`Trigger sent successfully for ${Number(data.duration) || Number(elements.slider.value)} seconds.`, 'success', 6000);
    if (data.audioToken) waitForReaction(data.audioToken);
  } catch (error) {
    if (error.retryAfter) state.cooldownUntil = Date.now() + error.retryAfter * 1000;
    setFeedback(error.message || 'Something went wrong. Please try again.', 'error', 6000);
  } finally {
    resetCaptcha();
    state.submitting = false;
    updateInterface();
    window.setTimeout(() => refreshStatus({ quiet: true }), 1500);
  }
}

async function unlockPremium() {
  const invoiceId = elements.invoice.value.trim();
  if (!invoiceId) return setFeedback('Enter your DigiSeller invoice number first.', 'error', 5000);
  elements.unlock.disabled = true;
  try {
    const response = await fetch('/api/premium.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ invoiceId }) });
    const data = await response.json();
    if (!response.ok || !data.ok || !data.available) throw new Error(data.message || 'The premium purchase could not be unlocked.');
    state.premium = true;
    state.premiumMaxDuration = Number(data.maxDuration) || 60;
    setFeedback(`Premium unlocked for one use, up to ${state.premiumMaxDuration} seconds at full strength.`, 'success', 7000);
    updateInterface();
  } catch (error) { setFeedback(error.message || 'The premium purchase could not be verified.', 'error', 7000); }
  finally { elements.unlock.disabled = false; }
}

async function loadPremium() {
  try {
    const response = await fetch('/api/premium.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const data = await response.json();
    if (data.ok && data.checkoutUrl) elements.premiumBuy.href = data.checkoutUrl;
  } catch { /* Premium is optional. */ }
}

function loadCaptcha() {
  if (!siteKey) {
    state.syncing = false;
    elements.captcha.textContent = 'Verification is not configured.';
    setFeedback('Missing VITE_HCAPTCHA_SITE_KEY. Verification cannot start.', 'error');
    updateInterface();
    return;
  }

  window.onHCaptchaLoad = () => {
    state.captchaId = window.hcaptcha.render(elements.captcha, {
      sitekey: siteKey,
      theme: 'light',
      callback: (token) => {
        state.captchaToken = token;
        setFeedback('Verification complete. Your trigger is ready to send.', 'success');
        updateInterface();
      },
      'expired-callback': () => {
        state.captchaToken = '';
        setFeedback('Verification expired. Please complete it again.', 'error');
        updateInterface();
      },
      'error-callback': () => {
        state.captchaToken = '';
        setFeedback('Verification could not load. Please try again.', 'error');
        updateInterface();
      },
    });
  };

  const script = document.createElement('script');
  script.src = 'https://js.hcaptcha.com/1/api.js?onload=onHCaptchaLoad&render=explicit';
  script.async = true;
  script.defer = true;
  document.head.append(script);
}

elements.slider.addEventListener('input', () => {
  elements.duration.textContent = elements.slider.value;
  const percentage = ((Number(elements.slider.value) - 5) / 15) * 100;
  elements.slider.style.setProperty('--range-progress', `${percentage}%`);
  updatePowerLimit();
});
elements.intensity.addEventListener('input', () => {
  elements.intensityOutput.textContent = elements.intensity.value;
  const percentage = ((Number(elements.intensity.value) - 1) / 99) * 100;
  elements.intensity.style.setProperty('--range-progress', `${percentage}%`);
});
elements.trigger.addEventListener('click', sendTrigger);
elements.unlock.addEventListener('click', unlockPremium);
elements.modeButtons.forEach((button) => button.addEventListener('click', () => setMode(button.dataset.mode, button.dataset.mode === state.mode)));
elements.preset.addEventListener('change', () => { state.points = presetPoints(elements.preset.value); drawWaveform(); });
elements.clearWaveform.addEventListener('click', () => { state.points = state.mode === 'preset' ? presetPoints(elements.preset.value) : defaultPoints(); if (state.mode === 'draw') saveWaveform(); drawWaveform(); });
function paintWaveform(event) {
  if (state.mode === 'direct') return;
  const rect = elements.canvas.getBoundingClientRect();
  const plotWidth = Math.max(1, rect.width - 50);
  const plotHeight = Math.max(1, rect.height - 38);
  const t = Math.max(0, Math.min(1, (event.clientX - rect.left - 38) / plotWidth));
  const rawValue = Math.max(0, Math.min(1, 1 - (event.clientY - rect.top - 18) / plotHeight));
  const limit = state.premium ? 100 : Math.max(40, Math.round(100 - ((Number(elements.slider.value) - 5) / 15) * 60));
  const v = Math.min(rawValue, limit / 100);
  if (rawValue > v) showToast(`This point is limited to ${limit}% at ${elements.slider.value} seconds.`);
  const pointIndex = Math.max(0, Math.min(state.points.length - 1, Math.round(t * (state.points.length - 1))));
  state.points[pointIndex] = { t: pointIndex / (state.points.length - 1), v };
  if (state.mode === 'draw') saveWaveform();
  drawWaveform();
}

elements.canvas.addEventListener('pointerdown', (event) => {
  if (state.mode === 'direct') return;
  elements.canvas.setPointerCapture?.(event.pointerId);
  paintWaveform(event);
});
elements.canvas.addEventListener('pointermove', (event) => {
  if (state.mode !== 'direct' && event.buttons === 1) paintWaveform(event);
});

elements.slider.dispatchEvent(new Event('input'));
elements.intensity.dispatchEvent(new Event('input'));
state.points = loadWaveform();
setMode('direct');
loadCaptcha();
loadPremium();
refreshStatus();
window.setInterval(updateInterface, 1000);
window.setInterval(() => refreshStatus({ quiet: true }), 15000);
