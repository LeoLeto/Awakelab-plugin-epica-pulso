/**
 * Genera amd/build/*.min.js (+ .map) a partir de amd/src/*.js sin necesitar un checkout de Moodle.
 *
 * Es el equivalente de `grunt amd` (babel + terser) para un fichero ya escrito en AMD:
 *  - Nombra el define() con el nombre del modulo (block_pulso/<fichero>): Moodle une varios
 *    modulos en una sola respuesta (lib/requirejs.php) y para eso el define tiene que llevar nombre.
 *  - Minimiza con terser y escribe el mapa de fuentes.
 *
 * Uso (desde tools/):  npm install && npm run build      (o: node build-amd.mjs --check)
 * Con --check no escribe nada: sale con error si amd/build no coincide con amd/src.
 */
import {minify} from 'terser';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const component = 'block_pulso';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const srcDir = path.join(root, 'amd', 'src');
const buildDir = path.join(root, 'amd', 'build');
const check = process.argv.includes('--check');

fs.mkdirSync(buildDir, {recursive: true});
let stale = 0;
for (const file of fs.readdirSync(srcDir).filter((f) => f.endsWith('.js')).sort()) {
    const name = file.replace(/\.js$/, '');
    const code = fs.readFileSync(path.join(srcDir, file), 'utf8').replace(/\r\n/g, '\n');
    const named = code.replace(/\bdefine\(\s*\[/, `define('${component}/${name}', [`);
    if (named === code) {
        throw new Error(`${file}: no se encontro "define([" para nombrar el modulo`);
    }
    const out = await minify({[file]: named}, {
        compress: {passes: 2},
        mangle: true,
        format: {comments: false},
        sourceMap: {filename: `${name}.min.js`, url: `${name}.min.js.map`},
    });
    const target = path.join(buildDir, `${name}.min.js`);
    const mapTarget = `${target}.map`;
    if (check) {
        // autocrlf puede dejar CRLF en el checkout: se compara sin saltos de linea de Windows.
        const read = (f) => fs.readFileSync(f, 'utf8').replace(/\r\n/g, '\n');
        const same = fs.existsSync(target) && read(target) === out.code
            && fs.existsSync(mapTarget) && read(mapTarget) === out.map;
        if (!same) {
            stale++;
            console.error(`DESACTUALIZADO: amd/build/${name}.min.js`);
        }
        continue;
    }
    fs.writeFileSync(target, out.code);
    fs.writeFileSync(mapTarget, out.map);
    console.log(`amd/build/${name}.min.js  ${(out.code.length / 1024).toFixed(1)} KB`);
}
if (check && stale) {
    process.exit(1);
}
