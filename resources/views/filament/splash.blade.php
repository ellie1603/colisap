<div id="colisap-splash" class="colisap-splash" role="status" aria-live="polite">
    <div class="colisap-splash__grid" aria-hidden="true"></div>

    <div class="colisap-splash__stage">
        <div class="colisap-splash__mark" aria-hidden="true">
            <svg class="colisap-splash__orbit" viewBox="0 0 160 160" fill="none">
                <defs>
                    <linearGradient id="colisap-orbit-gradient" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0" stop-color="#1E88C8" />
                        <stop offset=".55" stop-color="#FFD200" />
                        <stop offset="1" stop-color="#E8691C" />
                    </linearGradient>
                </defs>
                <circle class="colisap-splash__orbit-track" cx="80" cy="80" r="74" />
                <circle class="colisap-splash__orbit-line" cx="80" cy="80" r="74" stroke="url(#colisap-orbit-gradient)" />
            </svg>
            <div class="colisap-splash__disc">
                <img src="{{ asset('images/logo-192.png') }}" alt="">
            </div>
        </div>

        <p class="colisap-splash__wordmark" aria-label="COLISAP">
            @foreach (str_split('COLISAP') as $index => $letter)
                <span style="--i: {{ $index }}" aria-hidden="true">{{ $letter }}</span>
            @endforeach
        </p>

        <span class="colisap-splash__rule" aria-hidden="true"><i></i><i></i><i></i></span>

        <p class="colisap-splash__caption">Barbaza Multi-Purpose Cooperative</p>
        <p class="colisap-splash__greeting">Welcome back, {{ \Illuminate\Support\Str::of($userName)->before(' ') }}</p>
    </div>

    <script>
        (() => {
            const splash = document.getElementById('colisap-splash');
            const reduced = window.colisapIsLite ?? window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const dismiss = () => {
                if (splash.classList.contains('is-leaving')) {
                    return;
                }
                splash.classList.add('is-leaving');
                window.dispatchEvent(new CustomEvent('colisap:splash-done'));
                setTimeout(() => splash.remove(), reduced ? 300 : 1000);
            };
            splash.addEventListener('click', dismiss);
            setTimeout(dismiss, reduced ? 900 : 3000);
        })();
    </script>
</div>
