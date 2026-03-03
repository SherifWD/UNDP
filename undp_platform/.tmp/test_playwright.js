const { chromium } = require('playwright');
(async() => {
  const browser = await chromium.launch({ headless: true });
  console.log('ok');
  await browser.close();
})();
