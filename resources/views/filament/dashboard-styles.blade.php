{{-- Плитки инфопанели (App\Filament\Support\RoleQueueWidget): иконка в цветной подложке слева, полоса — у «требует действия». --}}
<style>
    .gf-stat { --gf-tone: var(--gray-400); padding: 1rem 1.25rem; transition: box-shadow .15s; }
    .gf-stat[data-tone="danger"] { --gf-tone: var(--danger-500); }
    .gf-stat[data-tone="warning"] { --gf-tone: var(--warning-500); }
    .gf-stat[data-tone="success"] { --gf-tone: var(--success-500); }
    .gf-stat[data-tone="info"] { --gf-tone: var(--info-500); }
    .gf-stat[data-tone="metric"] { --gf-tone: var(--primary-500); }
    .gf-stat[data-active] { --tw-inset-shadow: inset 4px 0 0 0 var(--gf-tone); }
    a.gf-stat:hover { --tw-ring-color: color-mix(in oklab, var(--gf-tone) 45%, transparent); }

    .gf-stat .fi-wi-stats-overview-stat-content { display: grid; grid-template-columns: 2.75rem minmax(0, 1fr); column-gap: .875rem; row-gap: .125rem; align-items: start; }
    .gf-stat .fi-wi-stats-overview-stat-label-ctn { display: contents; }
    .gf-stat .fi-wi-stats-overview-stat-label-ctn > .fi-icon {
        grid-column: 1; grid-row: 1 / span 3; width: 2.75rem; height: 2.75rem; padding: .625rem; border-radius: .75rem;
        color: var(--gf-tone); background: color-mix(in oklab, var(--gf-tone) 14%, transparent);
    }
    .gf-stat .fi-wi-stats-overview-stat-label { grid-column: 2; font-size: .8125rem; line-height: 1.25rem; color: var(--gray-500); }
    .gf-stat .fi-wi-stats-overview-stat-value { grid-column: 2; font-size: 1.75rem; line-height: 2.25rem; }
    .gf-stat .fi-wi-stats-overview-stat-description { grid-column: 2; font-size: .8125rem; }
    .gf-stat[data-tone="zero"] .fi-wi-stats-overview-stat-value { color: var(--gray-400); }
    .dark .gf-stat .fi-wi-stats-overview-stat-label { color: var(--gray-400); }
</style>
