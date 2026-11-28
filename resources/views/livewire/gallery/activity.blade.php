@use('App\Modules\Catalog\Support\ChartRange')
@use('App\Modules\Collection\Services\CollectionCover')
@use('App\Modules\Collection\Support\ValueChart')
@use('App\Support\DayLabel')
@use('App\Support\Money')

@php
    $headCurrency = array_key_first(array_filter($totals));
    $otherTotals = collect(array_filter($totals))->except($headCurrency);
    // The line's two ends, as drawn: after the coverage guard, so the move is the move of what the chart shows.
    $first = $chart ? $series->first()['minor'] : null;
    $last = $chart ? $series->last()['minor'] : null;
    // A move's sign comes from Money::move (true minus for a fall, ± for none). Colour is the view's job:
    // a rise is the accent, a fall is --color-flat, nothing else is coloured.
@endphp

<div class="act-page">
    <section class="act-head" aria-labelledby="act-h">
        <h1 id="act-h" class="act-who">Activity</h1>
        @if ($headCurrency)
            <p class="act-v act-figure">{{ Money::format($totals[$headCurrency], $headCurrency) }} @if ($chart)<small class="act-move price-move price-move--{{ $chart->up ? 'up' : ($chart->down ? 'down' : 'flat') }}">{{ trim(Money::glyph($last - $first).' '.Money::move($last - $first, $primaryCurrency)) }} · {{ CollectionCover::MOVE_LABEL }} · {{ $chartRange->label() }}</small>@endif</p>
            @if ($updatedAt)
                <p class="act-summary">Prices as of <span class="act-mono">{{ DayLabel::of($updatedAt) }}</span></p>
            @endif
        @else
            <p class="act-v act-figure">No prices yet</p>
        @endif
        @if ($headCurrency || $otherTotals->isNotEmpty())
            <p class="act-note">@if ($headCurrency)Approx. TCGplayer market prices.@endif @foreach ($otherTotals as $currency => $minor)Also {{ Money::format($minor, $currency) }} priced in {{ $currency }}, not added to the total. @endforeach</p>
        @endif
    </section>

    <section class="act-history" aria-labelledby="act-cv-h" data-act-history>
        <div class="act-tabs" role="tablist" aria-label="Collection value range" data-act-tabs>
            @foreach (ChartRange::cases() as $option)
                @php $isOn = $option === $chartRange; @endphp
                <button type="button" role="tab" id="act-tab-{{ $option->value }}" class="act-tab" aria-selected="{{ $isOn ? 'true' : 'false' }}" tabindex="{{ $isOn ? 0 : -1 }}" aria-controls="act-chart-panel" wire:click="selectRange('{{ $option->value }}')" wire:key="range-{{ $option->value }}">{{ $option->button() }}</button>
            @endforeach
        </div>
        <div class="act-sheet act-chart-sheet" role="tabpanel" id="act-chart-panel" aria-labelledby="act-tab-{{ $chartRange->value }}">
            <div class="act-ch-head">
                <div>
                    <h2 class="act-k" id="act-cv-h">Collection value · {{ $chartRange->label() }}</h2>
                    @if ($chart)
                        <p class="act-span act-mono">{{ DayLabel::of($series->first()['date']) }} – {{ DayLabel::of($series->last()['date']) }}</p>
                    @endif
                </div>
                {{-- The slot shows pointer and keyboard readings to the eye; only a keyboard reading reaches a screen reader, through the slider's aria-valuetext, so a mouse sweep does not flood it. --}}
                <p class="act-read act-mono" data-act-read aria-hidden="true"></p>
            </div>
            <div class="act-plot">
                @if ($chart)
                    @php
                        // The slider rests on the newest day; its value text is the same words the script writes for a reading.
                        $lastIndex = count($chart->points) - 1;
                        $restText = $chart->points[$lastIndex]['d'].' · '.$chart->points[$lastIndex]['v'];
                        $highLineY = round($chart->highY * ValueChart::HEIGHT / 100, 1);
                        $lowLineY = round($chart->lowY * ValueChart::HEIGHT / 100, 1);
                    @endphp
                    {{-- A slider's children are presentational, so the drawing is described here and the slider points at it. --}}
                    <p class="sr-only" id="act-chart-sum">Collection value over {{ $chartRange->label() }}, from {{ Money::format($first, $primaryCurrency) }} to {{ Money::format($last, $primaryCurrency) }}</p>
                    <div class="act-chart" tabindex="0" role="slider" aria-label="Value by day" aria-valuemin="0" aria-valuemax="{{ $lastIndex }}" aria-valuenow="{{ $lastIndex }}" aria-valuetext="{{ $restText }}" aria-describedby="act-chart-sum" data-act-chart data-points='@json($chart->points)'>
                        <svg class="act-svg" viewBox="0 0 {{ ValueChart::WIDTH }} {{ ValueChart::HEIGHT }}" preserveAspectRatio="none" aria-hidden="true">
                            <line class="act-grid" x1="0" x2="{{ ValueChart::WIDTH }}" y1="{{ $highLineY }}" y2="{{ $highLineY }}"/>
                            <line class="act-grid" x1="0" x2="{{ ValueChart::WIDTH }}" y1="{{ $lowLineY }}" y2="{{ $lowLineY }}"/>
                            <path class="act-area{{ $chart->down ? ' chart--down' : '' }}" d="{{ $chart->area }}"/>
                            <path class="act-line{{ $chart->down ? ' chart--down' : '' }}" d="{{ $chart->line }}"/>
                        </svg>
                        <span class="act-ylab act-mono" style="top: {{ $chart->highY }}%" aria-hidden="true">{{ $chart->high }}</span>
                        <span class="act-ylab act-mono" style="top: {{ $chart->lowY }}%" aria-hidden="true">{{ $chart->low }}</span>
                        <span class="act-cross" aria-hidden="true"></span>
                        <span class="act-dot" aria-hidden="true"></span>
                        <span class="act-end{{ $chart->up ? ' act-end--up' : '' }}{{ $chart->down ? ' chart--down' : '' }}" style="left: 100%; top: {{ $chart->endY }}%" aria-hidden="true"></span>
                        <span class="act-cover" aria-hidden="true"></span>
                    </div>
                    <p class="act-xlabs act-mono" aria-hidden="true"><span>{{ DayLabel::of($series->first()['date']) }}</span><span>{{ DayLabel::of($series->last()['date']) }}</span></p>
                @else
                    <p class="act-no-hist">A value chart appears once two daily price snapshots exist.</p>
                @endif
            </div>
            @if ($chart && $linePricedSince)
                <p class="act-since">The line starts on {{ $linePricedSince->format('j M') }}, the first day most of these cards had a price.</p>
            @elseif ($chart && $historySince)
                <p class="act-since">History since <span class="act-mono">{{ DayLabel::of($historySince) }}</span></p>
            @endif
        </div>
    </section>

    <section class="act-feed" aria-labelledby="act-fd-h">
        <div class="act-sec-head">
            <h2 class="act-sec-t" id="act-fd-h">Latest · {{ $feedCount }} {{ Str::plural('update', $feedCount) }}</h2>
            <p class="act-sec-r">Price moves under $1 are not listed.</p>
        </div>

        @forelse ($days as $day)
            @php $dayKey = $day['date']->toDateString(); $dayCount = $day['entries']->sum(fn (array $row) => $row['type'] === 'group' ? $row['count'] : 1); @endphp
            <section class="act-day" style="--i: {{ $loop->index }}" wire:key="day-{{ $dayKey }}">
                <h3 class="act-dtab" id="act-day-{{ $dayKey }}"><b>{{ DayLabel::of($day['date']) }}</b><span class="sr-only">, </span><span>{{ $dayCount }} {{ Str::plural('update', $dayCount) }}</span></h3>
                {{-- role="list": list-style: none makes WebKit/VoiceOver drop the list semantics otherwise --}}
                <ul class="act-dsheet" role="list">
                    @foreach ($day['entries'] as $entry)
                        @if ($entry['type'] === 'group')
                            @php
                                // The key is the one toggleGroup() takes: day and currency name one group.
                                $groupKey = $entry['day']->toDateString().'|'.$entry['currency'];
                                $isOpen = in_array($groupKey, $openGroups, true);
                            @endphp
                            <li class="act-row act-grp" wire:key="group-{{ $groupKey }}">
                                <span class="act-thumb act-grp-mark" aria-hidden="true"><span class="act-plus">+</span></span>
                                <div class="act-body">
                                    <p class="act-rt act-grp-t">{{ $entry['count'] }} small additions</p>
                                    <p class="act-rs act-grp-s">{{ $entry['setName'] }} · each {{ Money::format($entry['maxMinor'], $entry['currency']) }} or less</p>
                                </div>
                                <button type="button" class="act-grp-toggle" wire:click="toggleGroup('{{ $groupKey }}')" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">{{ $isOpen ? 'Hide' : 'Show all' }}</button>
                            </li>
                            @if ($isOpen)
                                <li class="act-grp-members" wire:key="members-{{ $groupKey }}">
                                    <ul class="act-grp-list" role="list">
                                        @foreach ($entry['entries'] as $member)
                                            <x-revamp.activity-row :entry="$member" :username="$targetUser->username" />
                                        @endforeach
                                    </ul>
                                </li>
                            @endif
                        @else
                            <x-revamp.activity-row :entry="$entry" :username="$targetUser->username" />
                        @endif
                    @endforeach
                </ul>
            </section>
        @empty
            <div class="act-empty">
                <div class="act-pockets" aria-hidden="true"><i></i><i></i><i></i></div>
                <h3 class="act-empty-t">Nothing yet</h3>
                <p>Additions and price movements show up here as they happen.</p>
            </div>
        @endforelse
    </section>
</div>

@push('head')
    @vite('resources/css/activity.css')
@endpush

@push('scripts')
    @vite('resources/js/activity.js')
@endpush
