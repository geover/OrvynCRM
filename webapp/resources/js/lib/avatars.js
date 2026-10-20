// A bucket of ready-made avatars for people who would rather not upload a photo: 30 characters, 15 female and 15 male, with their own look and mood.
// They are drawn here (soft, glossy, toy-like figures with shading and highlights), so there are no image files to ship. Choosing one turns it into a
// picture (a PNG with a transparent background) and sends it the way an uploaded photo is sent, so it shows everywhere a photo does.

const clamp = (n) => Math.max(0, Math.min(255, Math.round(n)));
const rgb = (hex) => { const h = hex.replace('#', ''); const n = parseInt(h.length === 3 ? h.replace(/./g, '$&$&') : h, 16); return [(n >> 16) & 255, (n >> 8) & 255, n & 255]; };
const hex = ([r, g, b]) => `#${[r, g, b].map((v) => clamp(v).toString(16).padStart(2, '0')).join('')}`;
const mix = (a, b, t) => { const x = rgb(a); const y = rgb(b); return hex(x.map((v, i) => v + (y[i] - v) * t)); };
const light = (c, t) => mix(c, '#ffffff', t);
const dark = (c, t) => mix(c, '#000000', t);

// ---- the cast. skin: 8 tones. hair: style + colour. eyes: colour + look. mouth, brows. outfit: style, colour, accent. bg: two colours.
const SK = { porcelain: '#fde3cf', fair: '#f6caa6', sand: '#e8b08a', tan: '#d19468', bronze: '#b57a50', brown: '#8d5a3a', deep: '#6b4129', ebony: '#4d2e1e' };
const HC = { black: '#1d1714', darkbrown: '#3a2518', brown: '#6b4226', chestnut: '#8a4b25', auburn: '#9c3d1f', blond: '#d8b35a', platinum: '#e8dcc0', ginger: '#c8561f', grey: '#a9a9b0', pink: '#e0629a', blue: '#4f7fd6', teal: '#2aa198', plum: '#7a3b8f' };

export const AVATARS = [
    // ---- female
    { id: 'f01', name: 'Aria', mood: 'Cheerful', g: 'f', skin: SK.fair, hair: ['long', HC.chestnut], eyes: ['#5b8c5a', 'open'], mouth: 'grin', brows: 'soft', outfit: ['tee', '#e76f51'], bg: ['#ffe8d6', '#f7b79b'] },
    { id: 'f02', name: 'Zoe', mood: 'Creative', g: 'f', skin: SK.sand, hair: ['bun', HC.plum], eyes: ['#7a4a2a', 'open'], mouth: 'smile', brows: 'raised', glasses: 'round', acc: 'earrings', outfit: ['hoodie', '#6a4c93'], bg: ['#e9dcff', '#b9a0f0'] },
    { id: 'f03', name: 'Maya', mood: 'Bold', g: 'f', skin: SK.deep, hair: ['afro', HC.black], eyes: ['#3a2418', 'open'], mouth: 'smirk', brows: 'bold', acc: 'earrings', outfit: ['blazer', '#264653'], bg: ['#fff1c9', '#f4c95d'] },
    { id: 'f04', name: 'Leila', mood: 'Calm', g: 'f', skin: SK.tan, hair: ['bob', HC.black], eyes: ['#3a2418', 'open'], mouth: 'calm', brows: 'soft', outfit: ['turtleneck', '#8d99ae'], bg: ['#dff3f0', '#9ad5cc'] },
    { id: 'f05', name: 'Nora', mood: 'Curious', g: 'f', skin: SK.porcelain, hair: ['wavy', HC.ginger], eyes: ['#4f8fb8', 'open'], mouth: 'smile', brows: 'raised', glasses: 'square', outfit: ['collar', '#3d5a80', '#ee6c4d'], bg: ['#ffe5d9', '#ffb59a'] },
    { id: 'f06', name: 'Ivy', mood: 'Playful', g: 'f', skin: SK.sand, hair: ['ponytail', HC.pink], eyes: ['#5a3d2b', 'wink'], mouth: 'open', brows: 'raised', acc: 'headband', accColor: '#ffd166', outfit: ['tee', '#06d6a0'], bg: ['#d8f5ff', '#8fd3f0'] },
    { id: 'f07', name: 'Sofia', mood: 'Elegant', g: 'f', skin: SK.fair, hair: ['bun', HC.platinum], eyes: ['#6c8ca8', 'open'], mouth: 'calm', brows: 'soft', acc: 'earrings', outfit: ['blazer', '#2b2d42'], bg: ['#f1eaff', '#c9b8ee'] },
    { id: 'f08', name: 'Chloe', mood: 'Sporty', g: 'f', skin: SK.bronze, hair: ['ponytail', HC.darkbrown], eyes: ['#3a2418', 'open'], mouth: 'grin', brows: 'bold', acc: 'cap', accColor: '#ef476f', outfit: ['hoodie', '#118ab2'], bg: ['#ffe3e8', '#ff9fb2'] },
    { id: 'f09', name: 'Ruby', mood: 'Confident', g: 'f', skin: SK.brown, hair: ['curly', HC.black], eyes: ['#2c1a10', 'open'], mouth: 'smirk', brows: 'bold', glasses: 'shades', acc: 'earrings', outfit: ['blazer', '#9b2226'], bg: ['#fff0d1', '#f5b971'] },
    { id: 'f10', name: 'Hana', mood: 'Gentle', g: 'f', skin: SK.porcelain, hair: ['bob', HC.black], eyes: ['#4a3426', 'happy'], mouth: 'smile', brows: 'soft', outfit: ['turtleneck', '#f4a6a6'], bg: ['#ffe9ef', '#f9b8cb'] },
    { id: 'f11', name: 'Tessa', mood: 'Witty', g: 'f', skin: SK.tan, hair: ['side', HC.auburn], eyes: ['#5b8c5a', 'wink'], mouth: 'smirk', brows: 'raised', glasses: 'round', outfit: ['collar', '#6d597a', '#e56b6f'], bg: ['#e8f5d9', '#b4dc8b'] },
    { id: 'f12', name: 'Dara', mood: 'Dreamy', g: 'f', skin: SK.ebony, hair: ['long', HC.black], eyes: ['#2c1a10', 'happy'], mouth: 'smile', brows: 'soft', acc: 'flower', outfit: ['tee', '#b5838d'], bg: ['#fde2ff', '#e6a6f2'] },
    { id: 'f13', name: 'Lena', mood: 'Focused', g: 'f', skin: SK.sand, hair: ['bob', HC.blue], eyes: ['#4a3426', 'open'], mouth: 'calm', brows: 'bold', glasses: 'square', outfit: ['turtleneck', '#1b263b'], bg: ['#dbe7ff', '#8fb1f2'] },
    { id: 'f14', name: 'Priya', mood: 'Radiant', g: 'f', skin: SK.bronze, hair: ['long', HC.black], eyes: ['#3a2418', 'open'], mouth: 'grin', brows: 'soft', acc: 'earrings', outfit: ['tee', '#f4a261'], bg: ['#fff3d6', '#f8cf7d'] },
    { id: 'f15', name: 'Mia', mood: 'Sunny', g: 'f', skin: SK.fair, hair: ['wavy', HC.blond], eyes: ['#5f9fd4', 'open'], mouth: 'open', brows: 'raised', outfit: ['hoodie', '#ffbe0b'], bg: ['#e2f4ff', '#a5d8f5'] },
    // ---- male
    { id: 'm01', name: 'Leo', mood: 'Friendly', g: 'm', skin: SK.fair, hair: ['quiff', HC.brown], beard: 'stubble', eyes: ['#5b8c5a', 'open'], mouth: 'grin', brows: 'soft', outfit: ['tee', '#3a86ff'], bg: ['#dbeafe', '#93c5fd'] },
    { id: 'm02', name: 'Kai', mood: 'Cool', g: 'm', skin: SK.tan, hair: ['spiky', HC.black], eyes: ['#2c1a10', 'open'], mouth: 'smirk', brows: 'bold', glasses: 'shades', outfit: ['hoodie', '#2b2d42'], bg: ['#ffe5ec', '#ff8fab'] },
    { id: 'm03', name: 'Omar', mood: 'Wise', g: 'm', skin: SK.brown, hair: ['short', HC.grey], beard: 'beard', eyes: ['#2c1a10', 'open'], mouth: 'smile', brows: 'bold', glasses: 'round', outfit: ['collar', '#355070', '#b56576'], bg: ['#ece3d0', '#cdb98f'] },
    { id: 'm04', name: 'Sam', mood: 'Easygoing', g: 'm', skin: SK.porcelain, hair: ['curly', HC.ginger], beard: 'stubble', eyes: ['#4f8fb8', 'open'], mouth: 'smile', brows: 'soft', outfit: ['tee', '#52b788'], bg: ['#e4f8ea', '#a8e0b6'] },
    { id: 'm05', name: 'Jonas', mood: 'Thoughtful', g: 'm', skin: SK.sand, hair: ['side', HC.darkbrown], beard: 'stubble', eyes: ['#5a3d2b', 'open'], mouth: 'calm', brows: 'soft', glasses: 'square', outfit: ['turtleneck', '#6c757d'], bg: ['#e9ecef', '#adb5bd'] },
    { id: 'm06', name: 'Mateo', mood: 'Adventurous', g: 'm', skin: SK.bronze, hair: ['short', HC.black], beard: 'mustache', eyes: ['#3a2418', 'open'], mouth: 'grin', brows: 'bold', acc: 'cap', accColor: '#2a9d8f', outfit: ['hoodie', '#e76f51'], bg: ['#fff0d9', '#fbc274'] },
    { id: 'm07', name: 'Noah', mood: 'Studious', g: 'm', skin: SK.fair, hair: ['side', HC.blond], beard: 'stubble', eyes: ['#6c8ca8', 'open'], mouth: 'calm', brows: 'raised', glasses: 'round', outfit: ['collar', '#264653', '#e9c46a'], bg: ['#e6f0ff', '#a9c4f5'] },
    { id: 'm08', name: 'Ethan', mood: 'Driven', g: 'm', skin: SK.deep, hair: ['buzz', HC.black], beard: 'goatee', eyes: ['#2c1a10', 'open'], mouth: 'calm', brows: 'bold', outfit: ['blazer', '#1d3557'], bg: ['#dceefb', '#7fb7e8'] },
    { id: 'm09', name: 'Liam', mood: 'Cheeky', g: 'm', skin: SK.porcelain, hair: ['quiff', HC.auburn], eyes: ['#5b8c5a', 'wink'], mouth: 'open', brows: 'raised', outfit: ['tee', '#2a9d8f'], bg: ['#fff4d6', '#ffd166'] },
    { id: 'm10', name: 'Arjun', mood: 'Warm', g: 'm', skin: SK.bronze, hair: ['short', HC.black], beard: 'stubble', eyes: ['#3a2418', 'happy'], mouth: 'smile', brows: 'soft', outfit: ['collar', '#6a994e', '#f2e8cf'], bg: ['#fdebd0', '#f5b57a'] },
    { id: 'm11', name: 'Felix', mood: 'Quirky', g: 'm', skin: SK.sand, hair: ['spiky', HC.teal], eyes: ['#7a4a2a', 'wink'], mouth: 'open', brows: 'raised', glasses: 'round', acc: 'headphones', accColor: '#ff6b6b', outfit: ['hoodie', '#7b2cbf'], bg: ['#e0fbfc', '#98d9dc'] },
    { id: 'm12', name: 'Diego', mood: 'Charming', g: 'm', skin: SK.tan, hair: ['quiff', HC.darkbrown], beard: 'mustache', eyes: ['#3a2418', 'open'], mouth: 'smirk', brows: 'soft', outfit: ['blazer', '#7f5539'], bg: ['#fde8d8', '#f0a97b'] },
    { id: 'm13', name: 'Hiro', mood: 'Serene', g: 'm', skin: SK.porcelain, hair: ['side', HC.black], eyes: ['#3a2c22', 'happy'], mouth: 'smile', brows: 'soft', outfit: ['turtleneck', '#457b9d'], bg: ['#e8f1f2', '#a9c7cc'] },
    { id: 'm14', name: 'Theo', mood: 'Laid-back', g: 'm', skin: SK.ebony, hair: ['curly', HC.black], eyes: ['#2c1a10', 'happy'], mouth: 'grin', brows: 'soft', acc: 'beanie', accColor: '#f4a261', outfit: ['hoodie', '#2a9d8f'], bg: ['#fff1e0', '#f8c08a'] },
    { id: 'm15', name: 'Marcus', mood: 'Bold', g: 'm', skin: SK.brown, hair: ['bald', HC.black], beard: 'beard', eyes: ['#2c1a10', 'open'], mouth: 'smirk', brows: 'bold', glasses: 'shades', outfit: ['blazer', '#343a40'], bg: ['#e9d8fd', '#b794f4'] },
    // ---- more of the same: the people you meet at work, in a suit, a blouse, a cardigan or a waistcoat
    { id: 'f16', name: 'Eleanor', mood: 'Professional', g: 'f', skin: SK.porcelain, hair: ['bob', HC.grey], eyes: ['#5f8fb4', 'open'], mouth: 'smile', brows: 'soft', glasses: 'round', acc: 'pearls', outfit: ['blazer', '#1f3a5f'], bg: ['#e8eef7', '#b6c6e0'] },
    { id: 'f17', name: 'Grace', mood: 'Poised', g: 'f', skin: SK.fair, hair: ['bun', HC.darkbrown], eyes: ['#6b4a2b', 'open'], mouth: 'calm', brows: 'soft', acc: 'pearls', outfit: ['blouse', '#274472', '#e9ecef'], bg: ['#f4ece6', '#d9c3b0'] },
    { id: 'f18', name: 'Naomi', mood: 'Executive', g: 'f', skin: SK.brown, hair: ['shoulder', HC.black], eyes: ['#2c1a10', 'open'], mouth: 'smile', brows: 'bold', acc: 'pearls', outfit: ['suit', '#2b2d42', '#c1121f'], bg: ['#eceff4', '#aeb8cc'] },
    { id: 'f19', name: 'Amira', mood: 'Diplomatic', g: 'f', skin: SK.tan, hair: ['shoulder', HC.black], eyes: ['#3a2418', 'open'], mouth: 'smile', brows: 'soft', acc: 'earrings', outfit: ['blouse', '#6d597a', '#f1e3f3'], bg: ['#f2e9f7', '#cdb4db'] },
    { id: 'f20', name: 'Claire', mood: 'Efficient', g: 'f', skin: SK.porcelain, hair: ['pixie', HC.blond], eyes: ['#4f8fb8', 'open'], mouth: 'calm', brows: 'bold', glasses: 'square', outfit: ['cardigan', '#7d8597'], bg: ['#eef2f6', '#c0cbd8'] },
    { id: 'f21', name: 'Yuki', mood: 'Precise', g: 'f', skin: SK.porcelain, hair: ['bob', HC.black], eyes: ['#3a2c22', 'open'], mouth: 'calm', brows: 'soft', outfit: ['blouse', '#e9eef5', '#3d5a80'], bg: ['#e3edf7', '#a9c4e0'] },
    { id: 'f22', name: 'Olivia', mood: 'Reliable', g: 'f', skin: SK.sand, hair: ['shoulder', HC.chestnut], eyes: ['#5b8c5a', 'open'], mouth: 'smile', brows: 'soft', outfit: ['cardigan', '#2a6f97'], bg: ['#e5f1f7', '#a7cfe3'] },
    { id: 'f23', name: 'Nadia', mood: 'Thoughtful', g: 'f', skin: SK.bronze, hair: ['wavy', HC.darkbrown], eyes: ['#3a2418', 'open'], mouth: 'smile', brows: 'soft', glasses: 'round', acc: 'pearls', outfit: ['blazer', '#5e548e'], bg: ['#efe9f8', '#c5b6e6'] },
    { id: 'f24', name: 'Rachel', mood: 'Organised', g: 'f', skin: SK.fair, hair: ['pixie', HC.auburn], eyes: ['#6b8e5a', 'open'], mouth: 'smile', brows: 'raised', glasses: 'round', outfit: ['blouse', '#2a9d8f', '#e6fffb'], bg: ['#e2f5f2', '#a1d8d0'] },
    { id: 'f25', name: 'Camille', mood: 'Refined', g: 'f', skin: SK.porcelain, hair: ['bun', HC.platinum], eyes: ['#6c8ca8', 'open'], mouth: 'calm', brows: 'soft', acc: 'pearls', outfit: ['suit', '#1b263b', '#8d99ae'], bg: ['#edf0f5', '#b9c3d6'] },
    { id: 'm16', name: 'James', mood: 'Professional', g: 'm', skin: SK.fair, hair: ['slick', HC.darkbrown], eyes: ['#5b8c5a', 'open'], mouth: 'smile', brows: 'soft', outfit: ['suit', '#1d3557', '#c1121f'], bg: ['#e6ecf5', '#a9bad6'] },
    { id: 'm17', name: 'William', mood: 'Dependable', g: 'm', skin: SK.porcelain, hair: ['side', HC.brown], eyes: ['#6c8ca8', 'open'], mouth: 'calm', brows: 'bold', glasses: 'square', outfit: ['vest', '#495057', '#6a994e'], bg: ['#eef0f2', '#bcc3ca'] },
    { id: 'm18', name: 'Daniel', mood: 'Analytical', g: 'm', skin: SK.sand, hair: ['short', HC.black], beard: 'stubble', eyes: ['#3a2418', 'open'], mouth: 'calm', brows: 'soft', glasses: 'round', outfit: ['cardigan', '#6c757d'], bg: ['#f1ece4', '#d3c4ad'] },
    { id: 'm19', name: 'David', mood: 'Executive', g: 'm', skin: SK.fair, hair: ['slick', HC.grey], beard: 'stubble', eyes: ['#5f8fb4', 'open'], mouth: 'smile', brows: 'bold', outfit: ['suit', '#212529', '#3a86ff'], bg: ['#e9edf2', '#aab4c3'] },
    { id: 'm20', name: 'Andre', mood: 'Diplomatic', g: 'm', skin: SK.ebony, hair: ['buzz', HC.black], beard: 'goatee', eyes: ['#2c1a10', 'open'], mouth: 'smile', brows: 'soft', outfit: ['suit', '#343a40', '#e9c46a'], bg: ['#f3ead9', '#dcc596'] },
    { id: 'm21', name: 'Robert', mood: 'Seasoned', g: 'm', skin: SK.porcelain, hair: ['bald', HC.grey], beard: 'mustache', eyes: ['#5f8fb4', 'open'], mouth: 'smile', brows: 'bold', glasses: 'round', outfit: ['vest', '#6b705c', '#9c6644'], bg: ['#eee9df', '#cbbf9f'] },
    { id: 'm22', name: 'Kenji', mood: 'Meticulous', g: 'm', skin: SK.porcelain, hair: ['side', HC.black], eyes: ['#3a2c22', 'open'], mouth: 'calm', brows: 'soft', glasses: 'square', outfit: ['suit', '#2b2d42', '#8d99ae'], bg: ['#e4eaf3', '#a8b8d2'] },
    { id: 'm23', name: 'Samuel', mood: 'Trustworthy', g: 'm', skin: SK.brown, hair: ['short', HC.black], beard: 'stubble', eyes: ['#2c1a10', 'open'], mouth: 'smile', brows: 'soft', outfit: ['blazer', '#774936'], bg: ['#f2e6da', '#d6b595'] },
    { id: 'm24', name: 'Victor', mood: 'Decisive', g: 'm', skin: SK.tan, hair: ['slick', HC.black], eyes: ['#3a2418', 'open'], mouth: 'smirk', brows: 'bold', outfit: ['suit', '#1b263b', '#ffb703'], bg: ['#e5eaf2', '#a3b3cf'] },
    { id: 'm25', name: 'Henry', mood: 'Approachable', g: 'm', skin: SK.sand, hair: ['quiff', HC.brown], beard: 'stubble', eyes: ['#5b8c5a', 'open'], mouth: 'grin', brows: 'soft', outfit: ['collar', '#4a6fa5', '#e63946'], bg: ['#e8f4f5', '#b2d8da'] },
];

// formal or semi-formal dress (a suit, a blazer, a blouse, a cardigan, a waistcoat or a shirt with a tie) against casual (a T-shirt, a hoodie, a polo neck)
const FORMAL = ['suit', 'blazer', 'blouse', 'cardigan', 'vest', 'collar'];
export const isFormal = (a) => FORMAL.includes(a.outfit[0]);

/** The picture of one avatar as SVG text, `size` pixels square, on a transparent background (`backdrop: true` puts it on its own soft colour). Every id inside is prefixed with the avatar's own, so many can sit on one page. */
export function avatarSvg(a, size = 200, { backdrop = false } = {}) {
    const p = `av${a.id}-`;
    const skin = a.skin; const skinLight = light(skin, 0.4); const skinDark = dark(skin, 0.2); const skinDeep = dark(skin, 0.35);
    const [hairStyle, hairColor] = a.hair;
    const hairHi = light(hairColor, 0.28); const hairLo = dark(hairColor, 0.3);
    const [outfit, outfitColor, outfitAccent] = a.outfit;
    const oHi = light(outfitColor, 0.25); const oLo = dark(outfitColor, 0.25);
    const accent = a.accColor ?? '#ef476f';
    const mouthColor = a.beard === 'beard' ? '#f0b8ae' : '#8a3a3a';
    const browColor = hairStyle === 'bald' ? dark(hairColor, 0.1) : hairLo;
    const [eyeColor, eyeLook] = a.eyes;
    const M = a.g === 'm';                                    // men get a squarer jaw, heavier brows, a broader neck and shoulders, bigger ears and nose, sideburns and no blush

    const defs = `<defs>
<radialGradient id="${p}bg" cx=".3" cy=".25" r="1.05"><stop offset="0" stop-color="${a.bg[0]}"/><stop offset="1" stop-color="${a.bg[1]}"/></radialGradient>
<radialGradient id="${p}skin" cx=".38" cy=".3" r=".85"><stop offset="0" stop-color="${skinLight}"/><stop offset=".55" stop-color="${skin}"/><stop offset="1" stop-color="${skinDark}"/></radialGradient>
<linearGradient id="${p}neck" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${skinDeep}"/><stop offset="1" stop-color="${skinDark}"/></linearGradient>
<linearGradient id="${p}hair" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${hairHi}"/><stop offset=".5" stop-color="${hairColor}"/><stop offset="1" stop-color="${hairLo}"/></linearGradient>
<linearGradient id="${p}out" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${oHi}"/><stop offset="1" stop-color="${oLo}"/></linearGradient>
<linearGradient id="${p}acc" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${light(accent, 0.3)}"/><stop offset="1" stop-color="${dark(accent, 0.2)}"/></linearGradient>
<radialGradient id="${p}shine" cx=".35" cy=".3" r=".7"><stop offset="0" stop-color="#fff" stop-opacity=".55"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></radialGradient>
</defs>`;

    const HAIR = `url(#${p}hair)`; const SKIN = `url(#${p}skin)`; const OUT = `url(#${p}out)`; const ACC = `url(#${p}acc)`;
    const gloss = (d) => `<path d="${d}" stroke="#fff" stroke-opacity=".3" stroke-width="6" stroke-linecap="round" fill="none"/>`;

    // ---- hair behind the head
    const back = {
        afro: `<circle cx="100" cy="68" r="62" fill="${HAIR}"/>`,
        long: `<path d="M50 82C40 132 44 168 58 178L142 178C156 168 160 132 150 82Z" fill="${HAIR}"/>`,
        wavy: `<path d="M50 82C38 118 52 134 44 156C50 172 62 166 66 180L134 180C138 166 150 172 156 156C148 134 162 118 150 82Z" fill="${HAIR}"/>`,
        bob: `<path d="M48 84C42 120 46 138 60 140L140 140C154 138 158 120 152 84Z" fill="${HAIR}"/>`,
        shoulder: `<path d="M48 84C42 118 48 146 62 152L138 152C152 146 158 118 152 84Z" fill="${HAIR}"/>`,
        bun: `<circle cx="100" cy="27" r="17" fill="${HAIR}"/><ellipse cx="94" cy="21" rx="7" ry="4" fill="#fff" fill-opacity=".25"/>`,
        ponytail: `<path d="M138 54C180 48 192 100 170 138C170 112 158 94 144 86Z" fill="${HAIR}"/>`,
        curly: `<circle cx="52" cy="82" r="13" fill="${HAIR}"/><circle cx="148" cy="82" r="13" fill="${HAIR}"/>`,
    }[hairStyle] ?? '';

    // ---- hair over the head
    const burns = M && ['short', 'side', 'quiff', 'slick', 'buzz', 'spiky'].includes(hairStyle) ? `<path d="M55 78L56 104L63 97L63 78Z" fill="${HAIR}"/><path d="M145 78L144 104L137 97L137 78Z" fill="${HAIR}"/>` : '';
    const cap = `M55 82C50 42 80 33 100 33C122 33 152 42 145 82C142 66 132 58 100 58C70 58 58 66 55 82Z`;
    const front = {
        short: `<path d="${cap}" fill="${HAIR}"/>${gloss('M72 50Q92 40 118 44')}`,
        bun: `<path d="${cap}" fill="${HAIR}"/>${gloss('M72 50Q92 40 118 44')}`,
        ponytail: `<path d="${cap}" fill="${HAIR}"/>${gloss('M72 50Q92 40 118 44')}`,
        side: `<path d="M55 84C48 44 78 31 102 33C128 35 152 46 145 84C140 68 128 60 112 56C96 66 70 66 58 78Z" fill="${HAIR}"/>${gloss('M70 48Q92 38 120 46')}`,
        buzz: `<path d="M57 80C54 48 80 38 100 38C120 38 146 48 143 80C138 64 128 56 100 56C72 56 62 64 57 80Z" fill="${HAIR}" fill-opacity=".7"/>`,
        quiff: `<path d="M55 82C46 50 66 30 100 28C134 30 154 50 145 82C140 62 126 56 100 58C76 56 60 62 55 82Z" fill="${HAIR}"/><ellipse cx="102" cy="31" rx="29" ry="12" fill="${HAIR}"/>${gloss('M72 40Q94 28 124 36')}`,
        spiky: `<path d="M56 80L50 52L72 62L72 32L92 54L100 26L110 54L128 32L128 62L150 52L144 80C138 64 124 58 100 58C76 58 62 64 56 80Z" fill="${HAIR}"/>${gloss('M72 56Q92 46 116 50')}`,
        long: `<path d="M54 84C48 42 76 32 100 32C124 32 152 42 146 84C142 64 124 52 100 54C76 52 58 64 54 84Z" fill="${HAIR}"/>${gloss('M72 46Q94 36 122 42')}`,
        wavy: `<path d="M54 84C48 42 76 32 100 32C124 32 152 42 146 84C142 64 124 52 100 54C76 52 58 64 54 84Z" fill="${HAIR}"/>${gloss('M72 46Q94 36 122 42')}`,
        bob: `<path d="M54 86C48 44 76 32 100 32C124 32 152 44 146 86C138 72 126 66 100 66C74 66 62 72 54 86Z" fill="${HAIR}"/>${gloss('M72 46Q94 36 122 42')}`,
        afro: `<path d="M56 78C60 52 80 46 100 46C120 46 140 52 144 78C136 64 122 58 100 58C78 58 64 64 56 78Z" fill="${HAIR}"/>`,
        curly: [64, 78, 92, 106, 120, 134].map((x, i) => `<circle cx="${x}" cy="${[M ? 54 : 56, M ? 46 : 46, M ? 43 : 42, M ? 43 : 42, M ? 46 : 46, M ? 54 : 56][i]}" r="${M ? 12.5 : 15}" fill="${HAIR}"/>`).join('') + `<circle cx="72" cy="40" r="3" fill="#fff" fill-opacity=".25"/>`,
        bald: `<ellipse cx="86" cy="52" rx="14" ry="8" fill="url(#${p}shine)"/>`,
        slick: `<path d="M55 80C48 46 76 30 100 30C128 30 152 46 145 80C140 64 130 52 100 50C72 52 60 64 55 80Z" fill="${HAIR}"/><path d="M70 50Q100 40 130 50M62 62Q82 52 100 52" stroke="${hairLo}" stroke-opacity=".55" stroke-width="2.2" stroke-linecap="round" fill="none"/>${gloss('M76 42Q102 33 128 42')}`,
        pixie: `<path d="M54 84C46 46 76 30 104 32C132 34 152 48 146 82C142 66 130 60 112 58C100 70 76 66 62 74Z" fill="${HAIR}"/><path d="M60 70C74 50 104 46 132 62C112 56 84 60 60 70Z" fill="#fff" fill-opacity=".18"/>${gloss('M72 44Q94 34 122 42')}`,
        shoulder: `<path d="M55 84C48 44 78 31 102 33C128 35 152 46 145 84C140 68 128 60 112 56C96 66 70 66 58 78Z" fill="${HAIR}"/>${gloss('M70 48Q92 38 120 46')}`,
    }[hairStyle] ?? '';

    // ---- body, neck, collar
    const hood = outfit === 'hoodie' ? `<path d="M60 152C58 124 82 122 100 138C118 122 142 124 140 152Z" fill="${oLo}"/>` : '';
    const shoulders = M ? 'M6 202C6 164 48 148 100 148C152 148 194 164 194 202Z' : 'M24 202C24 166 56 148 100 148C144 148 176 166 176 202Z';
    const body = `<path d="${shoulders}" fill="${OUT}"/><path d="${shoulders}" fill="url(#${p}shine)" opacity=".5"/>`;
    const neck = M
        ? `<ellipse cx="100" cy="128" rx="30" ry="10" fill="${skinDeep}" opacity=".35"/><path d="M80 112L78 150Q100 162 122 150L120 112Z" fill="url(#${p}neck)"/>`
        : `<ellipse cx="100" cy="128" rx="25" ry="9" fill="${skinDeep}" opacity=".35"/><path d="M86 112L86 150Q100 160 114 150L114 112Z" fill="url(#${p}neck)"/>`;
    const chest = {
        tee: `<path d="M83 148Q100 170 117 148Q100 158 83 148Z" fill="${skinDeep}"/>`,
        hoodie: `<path d="M84 148Q100 168 116 148Q100 156 84 148Z" fill="${skinDeep}"/><path d="M93 158L93 180M107 158L107 180" stroke="#fff" stroke-opacity=".75" stroke-width="2.6" stroke-linecap="round"/>`,
        blazer: `<path d="M84 148L100 178L116 148Z" fill="#f8fafc"/><path d="M82 148L100 178L72 172Z" fill="${oLo}"/><path d="M118 148L100 178L128 172Z" fill="${oLo}"/>`,
        turtleneck: `<path d="M80 126H120V152Q100 160 80 152Z" fill="${oHi}"/><path d="M82 140Q100 146 118 140" stroke="${oLo}" stroke-opacity=".4" stroke-width="2" fill="none"/>`,
        suit: `<path d="M86 148L100 174L114 148Z" fill="#f8fafc"/><path d="M96 154L104 154L106 182L100 190L94 182Z" fill="${outfitAccent ?? '#c1121f'}"/><path d="M82 147L100 178L70 174Z" fill="${oLo}"/><path d="M118 147L100 178L130 174Z" fill="${oLo}"/><path d="M74 176L84 168" stroke="#fff" stroke-opacity=".25" stroke-width="2" stroke-linecap="round"/>`,
        vest: `<path d="M84 146L100 168L116 146Z" fill="#f8fafc"/><path d="M96 156L104 156L105 184L100 190L95 184Z" fill="${outfitAccent ?? '#6a994e'}"/><path d="M86 150L100 184L114 150" stroke="${oLo}" stroke-width="3" fill="none" stroke-linejoin="round"/><circle cx="100" cy="196" r="2" fill="${oLo}"/>`,
        blouse: `<path d="M84 148L100 172L116 148Q100 157 84 148Z" fill="${skinDeep}"/><path d="M84 148L100 172L116 148" stroke="${outfitAccent ?? '#fff'}" stroke-width="3.2" fill="none" stroke-linejoin="round" stroke-linecap="round"/><path d="M84 148L92 158L80 160Z" fill="${outfitAccent ?? '#fff'}"/><path d="M116 148L108 158L120 160Z" fill="${outfitAccent ?? '#fff'}"/>`,
        cardigan: `<path d="M86 150L114 150L112 204L88 204Z" fill="#f8fafc"/><path d="M85 148L92 204M115 148L108 204" stroke="${oLo}" stroke-width="3" stroke-linecap="round"/><circle cx="100" cy="170" r="2.2" fill="${oLo}"/><circle cx="100" cy="186" r="2.2" fill="${oLo}"/>`,
        collar: `<path d="M84 146L100 164L80 168Z" fill="#fff"/><path d="M116 146L100 164L120 168Z" fill="#f1f5f9"/><path d="M96 164L104 164L107 190L100 197L93 190Z" fill="${outfitAccent ?? '#e76f51'}"/>`,
    }[outfit] ?? '';

    // ---- head
    const ears = [M ? 55 : 57, M ? 145 : 143].map((x) => `<ellipse cx="${x}" cy="90" rx="${M ? 8.5 : 7.5}" ry="${M ? 13 : 11.5}" fill="${skinDark}"/><ellipse cx="${x + (x < 100 ? 1 : -1)}" cy="91" rx="3.6" ry="6.5" fill="${skinDeep}" fill-opacity=".45"/>`).join('');
    const faceShape = M ? `<path d="M56 80C56 46 78 38 100 38C122 38 144 46 144 80L144 98C144 124 126 138 100 138C74 138 56 124 56 98Z" fill="${SKIN}"/>` : `<ellipse cx="100" cy="86" rx="42" ry="47" fill="${SKIN}"/>`;
    const head = `${faceShape}<ellipse cx="86" cy="62" rx="18" ry="11" fill="#fff" fill-opacity=".14"/>`;
    const blush = M ? 0.07 : 0.26;
    const cheeks = `<circle cx="75" cy="106" r="7.5" fill="#f28b82" fill-opacity="${blush}"/><circle cx="125" cy="106" r="7.5" fill="#f28b82" fill-opacity="${blush}"/>`;
    const nose = M
        ? `<ellipse cx="100" cy="104" rx="5.6" ry="4" fill="${skinDeep}" fill-opacity=".42"/><ellipse cx="99" cy="98" rx="2.6" ry="4.4" fill="#fff" fill-opacity=".28"/><path d="M93 92Q91 100 94 103" stroke="${skinDeep}" stroke-opacity=".35" stroke-width="2" fill="none" stroke-linecap="round"/>`
        : `<ellipse cx="100" cy="104" rx="4" ry="3" fill="${skinDeep}" fill-opacity=".38"/><ellipse cx="99" cy="99" rx="1.8" ry="3" fill="#fff" fill-opacity=".3"/>`;

    const eye = (x, look) => {
        if (look === 'happy') return `<path d="M${x - 7.5} 91Q${x} 80 ${x + 7.5} 91" stroke="#2a1d17" stroke-width="3.2" stroke-linecap="round" fill="none"/>`;
        const rx = M ? 6.2 : 7.4; const ry = M ? 6.8 : 8.4; const iris = M ? 4.3 : 5;
        const lid = M ? `<path d="M${x - 6.6} 84Q${x} 79 ${x + 6.6} 84" stroke="${skinDeep}" stroke-opacity=".5" stroke-width="1.4" stroke-linecap="round" fill="none"/>`
            : `<path d="M${x - 7.8} 85Q${x} 76 ${x + 7.8} 85" stroke="${dark(skin, 0.55)}" stroke-width="2.2" stroke-linecap="round" fill="none"/><path d="M${x + (x < 100 ? -7.8 : 7.8)} 85l${x < 100 ? -3 : 3} -2.6" stroke="${dark(skin, 0.55)}" stroke-width="1.8" stroke-linecap="round"/>`;
        return `<ellipse cx="${x}" cy="88" rx="${rx}" ry="${ry}" fill="#fff"/><circle cx="${x}" cy="88.6" r="${iris}" fill="${eyeColor}"/><circle cx="${x}" cy="88.6" r="${iris / 2}" fill="#16110e"/><circle cx="${x - 1.7}" cy="86.2" r="1.4" fill="#fff"/>${lid}`;
    };
    const eyes = eye(83, eyeLook === 'happy' ? 'happy' : 'open') + eye(117, eyeLook === 'open' ? 'open' : 'happy');
    const lift = a.brows === 'raised' ? -3 : 0; const thick = M ? (a.brows === 'bold' ? 6.2 : 5) : (a.brows === 'bold' ? 4.8 : 3.2);
    const by = M ? 77 : 75;                                   // men's brows sit lower and straighter
    const arch = M ? 2 : 5;
    const brows = `<path d="M74 ${by + lift}Q83 ${by - arch + lift} 92 ${by - 1 + lift}" stroke="${browColor}" stroke-width="${thick}" stroke-linecap="round" fill="none"/><path d="M108 ${by - 1 + lift}Q117 ${by - arch + lift} 126 ${by + lift}" stroke="${browColor}" stroke-width="${thick}" stroke-linecap="round" fill="none"/>`;
    const mouth = {
        smile: `<path d="M89 113Q100 125 111 113" stroke="${mouthColor}" stroke-width="2.8" stroke-linecap="round" fill="none"/>`,
        grin: `<path d="M88 112Q100 131 112 112Q100 117 88 112Z" fill="#fff" stroke="${mouthColor}" stroke-width="2.2" stroke-linejoin="round"/>`,
        smirk: `<path d="M90 117Q101 121 112 111" stroke="${mouthColor}" stroke-width="2.8" stroke-linecap="round" fill="none"/>`,
        calm: `<path d="M92 117Q100 120 108 117" stroke="${mouthColor}" stroke-width="2.8" stroke-linecap="round" fill="none"/>`,
        open: `<path d="M91 112Q100 134 109 112Z" fill="#7a2530" stroke="${mouthColor}" stroke-width="1.6" stroke-linejoin="round"/><ellipse cx="100" cy="122" rx="5" ry="3" fill="#e57373"/>`,
    }[a.mouth] ?? '';

    const faceHair = {
        stubble: `<path d="M58 96C60 130 80 138 100 138C120 138 140 130 142 96C134 114 120 121 100 121C80 121 66 114 58 96Z" fill="${hairColor}" fill-opacity=".25"/>`,
        beard: `<path d="M56 94C54 140 78 154 100 154C122 154 146 140 144 94C138 114 124 108 112 110Q100 106 88 110C76 108 62 114 56 94Z" fill="${HAIR}"/>`,
        mustache: `<path d="M86 108Q93 103 100 108Q107 103 114 108Q108 115 100 111Q92 115 86 108Z" fill="${HAIR}"/>`,
        goatee: `<path d="M86 108Q93 103 100 108Q107 103 114 108Q108 115 100 111Q92 115 86 108Z" fill="${HAIR}"/><path d="M91 126Q100 122 109 126Q107 137 100 139Q93 137 91 126Z" fill="${HAIR}"/>`,
    }[a.beard] ?? '';
    // the mouth is drawn over a full beard, so it stays visible; other beards sit under it
    const lower = a.beard === 'beard' ? faceHair + mouth : mouth + faceHair;

    const glasses = {
        round: `<g stroke="#2b2b33" stroke-width="2.6" fill="#fff" fill-opacity=".14"><circle cx="83" cy="88" r="13.5"/><circle cx="117" cy="88" r="13.5"/></g><path d="M96 87Q100 84 104 87M69.5 87L58 85M130.5 87L142 85" stroke="#2b2b33" stroke-width="2.6" fill="none" stroke-linecap="round"/>`,
        square: `<g stroke="#2b2b33" stroke-width="2.6" fill="#fff" fill-opacity=".14"><rect x="69" y="77" width="28" height="22" rx="6"/><rect x="103" y="77" width="28" height="22" rx="6"/></g><path d="M97 85Q100 83 103 85M69 85L58 84M131 85L142 84" stroke="#2b2b33" stroke-width="2.6" fill="none" stroke-linecap="round"/>`,
        shades: `<g fill="#1b1b22"><rect x="68" y="77" width="30" height="22" rx="9"/><rect x="102" y="77" width="30" height="22" rx="9"/></g><path d="M98 84Q100 82 102 84M68 84L58 83M132 84L142 83" stroke="#1b1b22" stroke-width="3" fill="none" stroke-linecap="round"/><path d="M74 82L82 80M108 82L116 80" stroke="#fff" stroke-opacity=".45" stroke-width="3" stroke-linecap="round"/>`,
    }[a.glasses] ?? '';

    const acc = {
        earrings: [57, 143].map((x) => `<circle cx="${x}" cy="103" r="3.6" fill="#f2c94c"/><circle cx="${x - 1}" cy="102" r="1.2" fill="#fff" fill-opacity=".8"/>`).join(''),
        headphones: `<path d="M54 82C52 28 148 28 146 82" stroke="#2d3142" stroke-width="6" fill="none" stroke-linecap="round"/><rect x="44" y="76" width="15" height="28" rx="7" fill="${ACC}"/><rect x="141" y="76" width="15" height="28" rx="7" fill="${ACC}"/>`,
        cap: `<path d="M52 72C50 30 150 30 148 72Z" fill="${ACC}"/><path d="M50 72Q100 62 150 72L160 78Q100 88 40 78Z" fill="${dark(accent, 0.15)}"/><circle cx="100" cy="36" r="4" fill="${light(accent, 0.3)}"/>`,
        beanie: `<path d="M52 76C46 30 154 30 148 76Q100 66 52 76Z" fill="${ACC}"/><path d="M49 74Q100 62 151 74L151 86Q100 74 49 86Z" fill="${dark(accent, 0.18)}"/><circle cx="100" cy="30" r="9" fill="${light(accent, 0.25)}"/>`,
        headband: `<path d="M54 74Q100 56 146 74L146 83Q100 65 54 83Z" fill="${ACC}"/>`,
        pearls: `<g fill="#f8f4ee" stroke="#d9d2c5" stroke-width=".8">${[0, 1, 2, 3, 4, 5, 6, 7, 8].map((i) => { const t = i / 8; const x = (1 - t) * (1 - t) * 82 + 2 * (1 - t) * t * 100 + t * t * 118; const y = (1 - t) * (1 - t) * 149 + 2 * (1 - t) * t * 168 + t * t * 149; return `<circle cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="2.6"/>`; }).join('')}<circle cx="57" cy="103" r="3.2"/><circle cx="143" cy="103" r="3.2"/></g>`,
        flower: `<g transform="translate(138 52)">${[0, 72, 144, 216, 288].map((r) => `<ellipse cx="0" cy="-8" rx="5.5" ry="7.5" fill="#ff9fb8" transform="rotate(${r})"/>`).join('')}<circle r="4.6" fill="#ffd166"/></g>`,
    }[a.acc] ?? '';

    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="${size}" height="${size}" role="img" aria-label="${a.name}, ${a.mood.toLowerCase()}">${defs}${backdrop ? `<rect width="200" height="200" fill="url(#${p}bg)"/>` : ''}`
        + `${back}${hood}${body}${chest}${neck}${ears}${head}${cheeks}${nose}${brows}${eyes}${lower}${burns}${front}${glasses}${acc}</svg>`;
}

/** The avatar as a PNG file, ready to be sent like an uploaded photo (512 pixels; the server makes it small). */
export async function avatarFile(a, size = 512) {
    const url = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(avatarSvg(a, size))}`;
    const img = new Image();
    img.src = url;
    await img.decode();
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = size;
    canvas.getContext('2d').drawImage(img, 0, 0, size, size);
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
    return new File([blob], `${a.id}.png`, { type: 'image/png' });
}
