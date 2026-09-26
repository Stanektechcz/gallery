<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Probíhá aktualizace</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f7f5f2;
            --fg: #2a2622;
            --muted: #6b645c;
            --card: #ffffff;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #1c1a18;
                --fg: #f2efe9;
                --muted: #a89f95;
                --card: #262320;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg);
            color: var(--fg);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            padding: 24px;
        }

        .karta {
            max-width: 420px;
            width: 100%;
            background: var(--card);
            border-radius: 16px;
            padding: 32px 28px;
            text-align: center;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }

        .ikona { font-size: 40px; margin-bottom: 12px; }

        h1 { font-size: 20px; margin: 0 0 8px; }

        p {
            margin: 0;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="karta">
        <div class="ikona">🛠️</div>
        <h1>Probíhá aktualizace</h1>
        <p>Za chvilku to bude zpátky.</p>
    </div>
</body>
</html>
