'use strict';
const fs = require('node:fs');
const crypto = require('node:crypto');
const path = require('node:path');
const { chromium } = require('playwright');
(async () => {
 const directory = path.join(__dirname, '../public/assets/brand');
 const svg = fs.readFileSync(directory + '/logo-h.svg');
 const browser = await chromium.launch({executablePath:process.env.CHROME_PATH || (fs.existsSync('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome') ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' : undefined),headless:true});
 try {
  const page = await browser.newPage({viewport:{width:720,height:130},deviceScaleFactor:2});
  await page.setContent('<style>html,body{margin:0;padding:0;background:transparent}img{display:block;width:720px;height:130px}</style><img alt="AIDEX" src="data:image/svg+xml;base64,' + svg.toString('base64') + '">');
  await page.locator('img').evaluate(async img => { await img.decode(); await document.fonts.ready; });
  await page.locator('img').screenshot({path:directory+'/logo-h-pdf.png',omitBackground:true});
  fs.writeFileSync(directory+'/logo-h-pdf.json',JSON.stringify({source:'logo-h.svg',source_sha256:crypto.createHash('sha256').update(svg).digest('hex'),derivative_sha256:crypto.createHash('sha256').update(fs.readFileSync(directory+'/logo-h-pdf.png')).digest('hex'),purpose:'Deterministic PDF raster fallback; original SVG preserved. No redesign or image generation.',viewport:[720,130],pixel_size:[1440,260],renderer:browser.version()},null,2)+'\n');
  console.log('Official SVG rendered to 1440x260 transparent PNG for PDF compatibility.');
 } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
