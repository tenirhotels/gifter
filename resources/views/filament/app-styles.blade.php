{{--
    Общие стили панели поверх темы Filament (цвета — только переменные темы).
    1) Плитки инфопанели (App\Filament\Support\RoleQueueWidget): иконка в цветной подложке;
       «требует действия» — тонкая рамка своего цвета со всех сторон (не полоса с одного края).
    0) Карточки просмотра (infolist): подпись мелкая и приглушённая, значение — основного цвета;
       подпись прижата к значению, а поля разделены большим отступом — глаз читает пары «подпись — значение».
    2) «Сводка» мастера оформления (CreateCertificate): закреплена справа, ведёт итоговая сумма.
--}}
<style>
    .fi-in-entry { row-gap: .25rem; }
    .fi-in-entry-label { font-size: .75rem; line-height: 1rem; font-weight: 500; letter-spacing: .01em; color: var(--gray-500); }
    .dark .fi-in-entry-label { color: var(--gray-400); }
    .fi-in-entry-content-col { font-size: .9375rem; }
    .fi-in-entry .fi-sc-text { font-size: .8125rem; color: var(--gray-500); }
    .dark .fi-in-entry .fi-sc-text { color: var(--gray-400); }

    .gf-stat { --gf-tone: var(--gray-400); padding: 1rem 1.25rem; }
    .gf-stat[data-tone="danger"] { --gf-tone: var(--danger-500); }
    .gf-stat[data-tone="warning"] { --gf-tone: var(--warning-500); }
    .gf-stat[data-tone="success"] { --gf-tone: var(--success-500); }
    .gf-stat[data-tone="info"] { --gf-tone: var(--info-500); }
    .gf-stat[data-tone="metric"] { --gf-tone: var(--primary-500); }
    .gf-stat[data-active] { --tw-ring-color: color-mix(in oklab, var(--gf-tone) 55%, transparent); background-image: linear-gradient(color-mix(in oklab, var(--gf-tone) 5%, transparent), color-mix(in oklab, var(--gf-tone) 5%, transparent)); }
    a.gf-stat:hover { --tw-ring-color: color-mix(in oklab, var(--gf-tone) 70%, transparent); }

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

    .gf-summary { position: sticky; top: 5rem; }
    .gf-summary-empty { color: var(--gray-500); font-size: .875rem; line-height: 1.5rem; padding-block: .5rem; }
    .gf-summary-total { display: flex; flex-direction: column; gap: .25rem; padding-bottom: 1rem; margin-bottom: 1rem; border-bottom: 1px solid color-mix(in oklab, var(--gray-950) 8%, transparent); }
    .dark .gf-summary-total { border-bottom-color: color-mix(in oklab, white 10%, transparent); }
    .gf-summary-total span { font-size: .8125rem; color: var(--gray-500); }
    .gf-summary-total strong { color: var(--gray-950); font-size: 2.25rem; line-height: 2.5rem; font-weight: 600; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
    .gf-summary-list { display: grid; gap: .75rem; margin: 0; }
    .gf-summary-row { display: grid; grid-template-columns: 7.5rem minmax(0, 1fr); gap: .75rem; font-size: .875rem; line-height: 1.375rem; }
    .gf-summary-row dt { color: var(--gray-500); }
    .gf-summary-row dd { margin: 0; overflow-wrap: anywhere; color: var(--gray-950); }
    .dark .gf-summary-total strong, .dark .gf-summary-row dd { color: var(--color-white); }
    .gf-muted { color: var(--gray-400); }
    .gf-nowrap { white-space: nowrap; }
    .gf-summary-warn { margin-top: 1rem; font-size: .875rem; color: var(--danger-600); }
    .dark .gf-summary-warn { color: var(--danger-400); }

    /* 3) Расчёт по прайсу в мастере. */
    .gf-quote { --gf-line: color-mix(in oklab, var(--gray-950) 8%, transparent); border: 1px solid var(--gf-line); border-radius: .75rem; overflow-x: auto; }
    .dark .gf-quote { --gf-line: color-mix(in oklab, white 10%, transparent); }
    .gf-quote table { width: 100%; border-collapse: collapse; font-size: .875rem; line-height: 1.25rem; }
    .gf-quote th { padding: .625rem 1rem; text-align: left; font-size: .75rem; font-weight: 500; color: var(--gray-500); background: color-mix(in oklab, var(--gray-500) 6%, transparent); border-bottom: 1px solid var(--gf-line); }
    .gf-quote td { padding: .625rem 1rem; border-bottom: 1px solid var(--gf-line); }
    .gf-quote tbody tr:last-child td { border-bottom: 0; }
    .gf-quote .gf-num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .gf-quote-group td { padding-top: .875rem; padding-bottom: .375rem; font-size: .75rem; font-weight: 500; color: var(--gray-500); border-bottom: 0; }
    .gf-quote tfoot td { border-top: 1px solid var(--gf-line); border-bottom: 0; color: var(--gray-500); }
    .gf-quote tfoot .gf-quote-total td { color: var(--gray-950); font-weight: 600; font-size: 1rem; }
    .dark .gf-quote tfoot .gf-quote-total td { color: var(--color-white); }

    /* 4) Ближайшие заезды: услуги с точкой статуса (зелёная — готово, жёлтая — нужно действие). */
    .gf-arrival-service { display: flex; align-items: baseline; gap: .375rem; font-size: .8125rem; line-height: 1.25rem; }
    .gf-arrival-service .gf-dot { flex: none; width: .5rem; height: .5rem; border-radius: 9999px; background: var(--warning-500); transform: translateY(-1px); }
    .gf-arrival-service[data-ok="1"] .gf-dot { background: var(--success-500); }

    /* 5) Безопасность: проверки (ошибки сверху), справа — бэкап и 2FA. */
    .gf-security { display: grid; gap: 1.5rem; align-items: start; }
    @media (min-width: 1024px) { .gf-security { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); } }
    .gf-security-side { display: grid; gap: 1.5rem; }
    .gf-security-value { font-size: 1rem; font-weight: 600; color: var(--gray-950); }
    .dark .gf-security-value { color: var(--color-white); }
    .gf-security-muted { margin-top: .25rem; font-size: .8125rem; color: var(--gray-500); }
    .gf-checks { width: 100%; border-collapse: collapse; font-size: .875rem; line-height: 1.25rem; }
    .gf-checks td { padding: .5rem .75rem .5rem 0; vertical-align: top; border-bottom: 1px solid color-mix(in oklab, var(--gray-950) 8%, transparent); }
    .dark .gf-checks td { border-color: color-mix(in oklab, white 10%, transparent); }
    .gf-checks tr:last-child td { border-bottom: 0; }
    .gf-checks-status { width: 6.5rem; }
    .gf-checks-name { width: 40%; font-weight: 500; color: var(--gray-950); }
    .dark .gf-checks-name { color: var(--color-white); }
    .gf-checks-hint { color: var(--gray-500); }
    .gf-checks-badge { display: inline-block; padding: .0625rem .5rem; border-radius: .375rem; font-size: .75rem; font-weight: 500;
        color: var(--success-700); background: color-mix(in oklab, var(--success-500) 12%, transparent); }
    .gf-checks [data-status="warn"] .gf-checks-badge { color: var(--warning-700); background: color-mix(in oklab, var(--warning-500) 15%, transparent); }
    .gf-checks [data-status="fail"] .gf-checks-badge { color: var(--danger-700); background: color-mix(in oklab, var(--danger-500) 12%, transparent); }
    .dark .gf-checks-badge { color: var(--success-400); }
    .dark .gf-checks [data-status="warn"] .gf-checks-badge { color: var(--warning-400); }
    .dark .gf-checks [data-status="fail"] .gf-checks-badge { color: var(--danger-400); }

    /* 6) Отчёт по продавцам: таблицы сводки и месяцев. */
    .gf-report-scroll { overflow-x: auto; }
    .gf-report { width: 100%; border-collapse: collapse; font-size: .875rem; line-height: 1.25rem; }
    .gf-report th { text-align: left; font-weight: 500; font-size: .75rem; color: var(--gray-500); padding: .5rem .75rem; border-bottom: 1px solid var(--gf-line, color-mix(in oklab, var(--gray-950) 10%, transparent)); white-space: nowrap; }
    .gf-report td { padding: .5rem .75rem; border-bottom: 1px solid color-mix(in oklab, var(--gray-950) 6%, transparent); }
    .gf-report .gf-num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .gf-report tfoot td { font-weight: 600; border-bottom: 0; border-top: 1px solid color-mix(in oklab, var(--gray-950) 10%, transparent); }
    .gf-report-muted { margin-top: .75rem; font-size: .8125rem; color: var(--gray-500); }
</style>
