{{--
    An invoice, built to be printed.

    Deliberately plain and self-contained: no stylesheet from the app, no web fonts, no
    dark mode. A document somebody files with their accounts should look the same in five
    years as it does today, and should not depend on anything that can be redesigned.
--}}
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Faktura {{ $invoice->number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 2.5rem; font: 14px/1.5 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #111; background: #fff; }
        .sheet { max-width: 46rem; margin: 0 auto; }
        header { display: flex; justify-content: space-between; align-items: flex-start; gap: 2rem; border-bottom: 2px solid #111; padding-bottom: 1rem; }
        h1 { margin: 0; font-size: 1.4rem; }
        .muted { color: #555; font-size: 12px; }
        .parties { display: flex; gap: 3rem; margin: 2rem 0; }
        .parties > div { flex: 1; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: #555; margin: 0 0 .4rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; }
        th, td { text-align: left; padding: .6rem 0; border-bottom: 1px solid #ddd; }
        td.amount, th.amount { text-align: right; white-space: nowrap; }
        .total { font-size: 1.15rem; font-weight: 700; border-bottom: none; padding-top: 1rem; }
        footer { margin-top: 3rem; border-top: 1px solid #ddd; padding-top: 1rem; }
        .print { margin-top: 2rem; }
        button { font: inherit; padding: .6rem 1.2rem; border: 1px solid #111; background: #111; color: #fff; border-radius: .4rem; cursor: pointer; }
        /* The button is for the screen; a printed page showing "Vytisknout" is a mistake. */
        @media print { .print { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
<div class="sheet">
    <header>
        <div>
            <h1>Faktura {{ $invoice->number }}</h1>
            <p class="muted">
                Vystaveno {{ $invoice->issued_at?->format('j. n. Y') }}
                @if ($invoice->paid_at) · Uhrazeno {{ $invoice->paid_at->format('j. n. Y') }} @endif
            </p>
        </div>
        <div class="muted" style="text-align: right">
            MAKI Gallery<br>
            gallery.stanektech.cz
        </div>
    </header>

    <div class="parties">
        <div>
            <h2>Dodavatel</h2>
            MAKI Gallery<br>
            <span class="muted">gallery.stanektech.cz</span>
        </div>
        <div>
            <h2>Odběratel</h2>
            {{ $invoice->customer_name ?: 'Zákazník' }}<br>
            <span class="muted">{{ $invoice->customer_email }}</span><br>
            <span class="muted">Prostor: {{ $space->name }}</span>
        </div>
    </div>

    <table>
        <thead>
            <tr><th>Položka</th><th class="amount">Částka</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $invoice->description }}</td>
                <td class="amount">{{ number_format($invoice->amount / 100, 2, ',', ' ') }} {{ $invoice->currency }}</td>
            </tr>
            @if ($invoice->vat_rate > 0)
                <tr>
                    <td>DPH {{ $invoice->vat_rate }} %</td>
                    <td class="amount">{{ number_format($invoice->amount / 100 * $invoice->vat_rate / 100, 2, ',', ' ') }} {{ $invoice->currency }}</td>
                </tr>
            @endif
            <tr>
                <td class="total">Celkem</td>
                <td class="total amount">
                    {{ number_format($invoice->amount / 100 * (1 + $invoice->vat_rate / 100), 2, ',', ' ') }} {{ $invoice->currency }}
                </td>
            </tr>
        </tbody>
    </table>

    <footer class="muted">
        @if ($invoice->vat_rate == 0)
            Nejsme plátci DPH.
        @endif
        Doklad byl vystaven elektronicky a je platný bez podpisu.
    </footer>

    <div class="print">
        <button type="button" onclick="window.print()">Vytisknout nebo uložit jako PDF</button>
    </div>
</div>
</body>
</html>
