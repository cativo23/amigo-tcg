// WCAG contrast for the design tokens, so a colour can be checked in a test instead of by eye.
// Reads the two notations revamp.css and app.css use: oklch(L% C H) and #rrggbb.

const clamp01 = (v) => Math.min(1, Math.max(0, v));
const encode = (c) => (c <= 0.0031308 ? 12.92 * c : 1.055 * c ** (1 / 2.4) - 0.055);
const decode = (c) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);

/** @returns {[number, number, number]} linear-light sRGB, each 0..1 (gamut-clipped). */
function oklchToLinear(L, C, H) {
    const h = (H * Math.PI) / 180;
    const a = C * Math.cos(h);
    const b = C * Math.sin(h);
    const l = (L + 0.3963377774 * a + 0.2158037573 * b) ** 3;
    const m = (L - 0.1055613458 * a - 0.0638541728 * b) ** 3;
    const s = (L - 0.0894841775 * a - 1.291485548 * b) ** 3;
    return [
        4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
        -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
        -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
    ].map(clamp01);
}

/** @returns {[number, number, number]} gamma-encoded sRGB, each 0..1. */
export function parseColor(value) {
    const text = value.trim();
    const hex = text.match(/^#([0-9a-f]{6})$/i);
    if (hex) {
        return [0, 2, 4].map((i) => parseInt(hex[1].slice(i, i + 2), 16) / 255);
    }
    const ok = text.match(/^oklch\(\s*([\d.]+)%\s+([\d.]+)\s+([\d.]+)\s*\)$/i);
    if (ok) {
        return oklchToLinear(Number(ok[1]) / 100, Number(ok[2]), Number(ok[3])).map(encode);
    }
    throw new Error(`Unsupported colour: ${value}`);
}

const luminance = (value) => {
    const [r, g, b] = parseColor(value).map(decode);
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};

/** WCAG 2 contrast ratio between two colours, 1..21. */
export function contrastRatio(foreground, background) {
    const [hi, lo] = [luminance(foreground), luminance(background)].sort((x, y) => y - x);
    return (hi + 0.05) / (lo + 0.05);
}

/** OKLCH hue angle in degrees, for any colour parseColor accepts. */
export function hueOf(value) {
    const [r, g, b] = parseColor(value).map(decode);
    const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
    const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
    const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
    const a = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s;
    const bb = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;
    return ((Math.atan2(bb, a) * 180) / Math.PI + 360) % 360;
}
