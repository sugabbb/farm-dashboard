import fs from 'node:fs';
import path from 'node:path';
import PHPParser from 'php-parser';
const parser = new PHPParser({parser:{extractDoc:true,phpVersion:'8.3'},ast:{withPositions:true}});
let failures=0, count=0;
function walk(dir){for(const file of fs.readdirSync(dir,{withFileTypes:true})) {const p=path.join(dir,file.name);if(file.isDirectory()) walk(p); else if(p.endsWith('.php')&&!p.endsWith('.blade.php')) {try{parser.parseCode(fs.readFileSync(p,'utf8'),p);count++;}catch(e){process.stderr.write(`${p}: ${e.message}\n`);failures++;}}}}
for(const dir of ['app','bootstrap','config','database','routes','tests']) walk(dir);
process.stdout.write(`${count} PHP files parsed; ${failures} syntax failures. This is static parsing, not a PHP runtime test.\n`);
process.exitCode=failures?1:0;
