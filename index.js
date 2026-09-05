import { ButtplugClient } from "@zendrex/buttplug.js";

const client = new ButtplugClient("ws://127.0.0.1:12345/buttplug");

async function startVibrationLoop(device) {
  if (!device.canOutput("Vibrate")) {
    console.error(`Устройство ${device.name} не поддерживает вибрацию.`);
    return;
  }
  console.log(`Запускаю цикл вибрации для ${device.name}: 5 сек вкл, 5 сек выкл.`);
  while (true) {
    try {
      console.log("Включаю вибрацию (100%)");
      await device.vibrate(1.0);
      await new Promise(r => setTimeout(r, 5000));

      console.log("Выключаю вибрацию");
      await device.stop();
      await new Promise(r => setTimeout(r, 5000));
    } catch (err) {
      console.error("Ошибка вибрации:", err.message);
      break;
    }
  }
}

client.on("device.added", ({ data: { device } }) => {
  console.log(`Новое устройство: ${device.name}`);
  startVibrationLoop(device);
});

async function main() {
  try {
    await client.connect();
    console.log("Подключено к Intiface Central.");

    await client.requestDeviceList();
    console.log(`Найдено устройств: ${client.devices.length}`);
    for (const device of client.devices) {
      startVibrationLoop(device);
    }

    await client.startScanning();
    console.log("Сканирование запущено. Ожидание новых устройств...");
  } catch (err) {
    console.error("Ошибка:", err.message);
  }
}

main();