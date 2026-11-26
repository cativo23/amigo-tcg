// Pure parts of landing-stage.js, tested by `npm run test:js`.

import { CAL_MAX, CAL_MIN, CARD_MM, PX_PER_MM, parseCalibration } from './plate-helpers.js';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** A YYYY-MM-DD day as the site writes days: "4 Nov", with the year only when it is not the current one (DayLabel::of). */
export function dayLabel(iso, todayIso) {
    const [year, month, day] = iso.split('-').map(Number);
    const label = `${day} ${MONTHS[month - 1]}`;

    return year === Number(todayIso.slice(0, 4)) ? label : `${label} ${year}`;
}

/** The label under the landing's price figures: a fixed snapshot, so it gives its day, never "today". */
export function figureSource(capturedOn, todayIso) {
    return `TCGplayer · approx. · ${dayLabel(capturedOn, todayIso)}`;
}

/**
 * The size act's card: 63 × 88 mm in CSS pixels, times the calibration a card page stored on this device
 * (`localStorage['amigo-tcg-cal']`, read only). A missing or out-of-range value means the size is approximate.
 */
export function matCard(raw) {
    const cal = parseCalibration(raw);
    const value = typeof raw === 'string' && raw.trim() !== '' ? Number(raw) : Number.NaN;
    const matched = Number.isFinite(value) && value >= CAL_MIN && value <= CAL_MAX;

    return {
        cal,
        matched,
        w: CARD_MM.w * PX_PER_MM * cal,
        h: CARD_MM.h * PX_PER_MM * cal,
        caption: matched ? 'Official art · matched to your card' : 'Official art · approx. until matched to a real card',
    };
}
