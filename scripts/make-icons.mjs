/**
 * Generates the PWA icons in public/icons (gitignored) from the installation's
 * logo, and public/favicon.ico (16, 32 and 48 px; gitignored too): build-profile/logo.png when the profile names one (scripts/profile-assets.php),
 * else the neutral resources/images/logo.png. `npm run build` runs it first;
 * `npm run icons` alone after changing the logo. A wide logo is centred on a
 * white square.
 */
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';

import sharp from 'sharp';

const source = existsSync('build-profile/logo.png') ? 'build-profile/logo.png' : 'resources/images/logo.png';
const out = 'public/icons';

// [file, size, logo width as a share of the square]
const icons = [
    ['icon-192.png', 192, 0.9],
    ['icon-512.png', 512, 0.9],
    // Maskable: the logo must stay inside the central safe circle (80 % diameter).
    ['maskable-512.png', 512, 0.66],
    ['apple-touch-icon.png', 180, 0.86],
    ['favicon-32.png', 32, 0.96],
];

mkdirSync(out, { recursive: true });
for (const [file, size, share] of icons) {
    const logo = await sharp(source).resize({ width: Math.round(size * share), height: Math.round(size * share), fit: 'inside' }).toBuffer();
    await sharp({ create: { width: size, height: size, channels: 4, background: '#ffffff' } })
        .composite([{ input: logo, gravity: 'centre' }])
        .png()
        .toFile(`${out}/${file}`);
}
// favicon.ico: PNG images in an ICO container (every current browser reads them).
const square = async (size) => {
    const logo = await sharp(source).resize({ width: size, height: size, fit: 'inside' }).toBuffer();
    return sharp({ create: { width: size, height: size, channels: 4, background: '#ffffff' } }).composite([{ input: logo, gravity: 'centre' }]).png().toBuffer();
};
const sizes = [16, 32, 48];
const images = await Promise.all(sizes.map(square));
const header = Buffer.alloc(6 + 16 * sizes.length);
header.writeUInt16LE(0, 0);
header.writeUInt16LE(1, 2);
header.writeUInt16LE(sizes.length, 4);
let offset = header.length;
sizes.forEach((size, i) => {
    const entry = 6 + 16 * i;
    header.writeUInt8(size, entry);
    header.writeUInt8(size, entry + 1);
    header.writeUInt16LE(1, entry + 4);
    header.writeUInt16LE(32, entry + 6);
    header.writeUInt32LE(images[i].length, entry + 8);
    header.writeUInt32LE(offset, entry + 12);
    offset += images[i].length;
});
writeFileSync('public/favicon.ico', Buffer.concat([header, ...images]));
console.log(`make-icons: ${out}/ and public/favicon.ico from ${source}`);
