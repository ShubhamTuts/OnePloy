@props([
    'title',
    'description' => null,
])

<section class="auth-shell application-settings-form">
    <div class="auth-shell-content">
        <div class="mb-6 flex justify-center">
            <img src="{{ asset('oneploy-wordmark-dark.png') }}" alt="OnePloy" class="hidden h-10 dark:block" />
            <img src="{{ asset('oneploy-wordmark-light.png') }}" alt="OnePloy" class="h-10 dark:hidden" />
        </div>
        <div class="auth-card">
            <div class="auth-card-heading">
                <h1>{{ $title }}</h1>
                @if ($description)
                    <p>{{ $description }}</p>
                @endif
            </div>

            <div class="auth-card-body">
                {{ $slot }}
            </div>

            @isset($footer)
                <footer class="auth-card-footer">
                    {{ $footer }}
                </footer>
            @endisset
        </div>
    </div>
</section>
