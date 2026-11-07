import fs from 'node:fs';
import { launch, context, login, api, sleep } from './lib.mjs';
const D = process.argv[2];
const b = await launch(); const c = await context(b); const p = await c.newPage(); await login(p);
for (const f of ['pdf','pptx']) {
  const r = await api(p,'POST',`/api/decks/${D}/exports`,{format:f,options:{}});
  let e = r.json; for (let i=0;i<60 && e.status!=='done' && e.status!=='failed';i++){ await sleep(1000); e=(await api(p,'GET',`/api/exports/${e.id}`)).json; }
  console.log(f, e.status, e.size, e.error);
  const bytes = await p.evaluate(async (u)=>{const r=await fetch(u); const b=new Uint8Array(await r.arrayBuffer()); let s=''; for(let i=0;i<b.length;i+=0x8000) s+=String.fromCharCode(...b.subarray(i,i+0x8000)); return btoa(s);}, e.download_url); fs.writeFileSync(`/tmp/press-b-out/rich.${f}`, Buffer.from(bytes,'base64'));
}
await b.close();
