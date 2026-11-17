// Pure parts of landing-stage.js, tested by `npm run test:js`.

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
