import { chromium } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const output=resolve('.local/terminal-design');
await mkdir(output,{recursive:true});
const browser=await chromium.launch({headless:true,channel:process.env.TERMINAL_PREVIEW_BROWSER || 'msedge'});
try {
 const page=await browser.newPage({viewport:{width:1120,height:900},deviceScaleFactor:1});
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(pathToFileURL(resolve('docs/terminal/terminal48-elegant-preview.html')).href);
 for(const size of ['800,600','784,521']) {
  await page.locator('#size').selectOption(size);
  for(const screen of ['home','login','manager','pickup','send','driver','door','confirm','success','recovery','help']) {
   await page.locator('#screen').selectOption(screen);
   const overflow=await page.locator('#main').evaluate(el=>({vertical:el.scrollHeight>el.clientHeight+1,horizontal:el.scrollWidth>el.clientWidth+1}));
   if(overflow.vertical||overflow.horizontal){await page.locator('#kiosk').screenshot({path:resolve(output,'overflow.png')});console.log(await page.locator('#main,.manager-grid,.inspector,.towers,.legend').evaluateAll(els=>els.map(el=>({class:el.className,height:el.clientHeight,scroll:el.scrollHeight}))));throw new Error(`${screen} ${size}: main overflow ${JSON.stringify(overflow)}`);}
   if(screen==='manager'){
    if(await page.locator('[data-door]').count()!==32 || await page.locator('.controller').count()!==1)throw new Error('Wrong physical/controller count');
    const clipped=await page.locator('.inspector').evaluate(el=>el.scrollHeight>el.clientHeight+1);
    if(clipped){console.log(await page.locator('.inspector').evaluate(el=>({height:el.clientHeight,scroll:el.scrollHeight,children:[...el.children].map(x=>({tag:x.tagName,height:x.getBoundingClientRect().height}))})));await page.locator('#kiosk').screenshot({path:resolve(output,'overflow.png')});throw new Error(`Inspector overflow ${size}`);}
   }
   if(['home','login','manager','send','confirm','recovery'].includes(screen))await page.locator('#kiosk').screenshot({path:resolve(output,`${screen}-${size.replace(',','x')}.png`)});
  }
 }
 await page.locator('#screen').selectOption('login');
 await page.locator('#code').fill('1234');await page.locator('#login-form button[type=submit]').click();
 await page.locator('[data-tower="2"]').click();
 await page.locator('#door-choice').selectOption('9');
 await page.locator('[data-action="open-tower"]').click();
 if(!(await page.getByRole('dialog').innerText()).includes('Open 16 doors?'))throw new Error('Wrong tower target count');
 await page.locator('[data-action="cancel"]').click();
 if(await page.locator('.cell.open').count())throw new Error('Cancel changed door state');
 await page.locator('[data-action="open-door"]').click();await page.locator('[data-action="confirm-open"]').click();
 if(await page.locator('.cell.open').count()!==1 || !await page.locator('[data-action="open-tower"]').isDisabled())throw new Error('Pending operation gate missing');
 await page.locator('#advance').click();
 await page.locator('#offline').check();
 if(!await page.locator('[data-action="open-door"]').isDisabled())throw new Error('Offline opening enabled');
 await page.locator('#kiosk').screenshot({path:resolve(output,'manager-offline.png')});
 if(errors.length)throw new Error(errors.join('\n'));
 console.log('PASS: 11 screens at both kiosk sizes; 32 doors + controller, admin keypad, selected door, 16-door tower confirmation, cancellation, pending and offline states.');
 console.log(`Preview images: ${output}`);
}finally{await browser.close();}
