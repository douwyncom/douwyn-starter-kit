<div class="dw-auth-layout">
    <section class="dw-auth-brand" aria-label="Douwyn">
        <div class="dw-auth-brand-header">
            @include('filament.components.brand')

            <x-filament-panels::theme-switcher />
        </div>

        <div class="dw-auth-brand-copy">
            <p class="dw-auth-meta">{{ __('Administration workspace') }}</p>
            <p class="dw-auth-statement">{{ __('Secure access for authorized operators.') }}</p>
        </div>
    </section>

    <section class="dw-auth-panel">
        <div class="dw-auth-card">
            <header class="dw-auth-header">
                <p class="dw-auth-meta">{{ __('Administration workspace') }}</p>
                <h1 class="dw-auth-title">
                    {{ $step === 'otp' ? __('Verify your account') : __('Sign in') }}
                </h1>

                <p class="dw-auth-description">
                    {{ $step === 'otp'
                        ? __('Enter the verification code to continue.')
                        : __('Welcome back. Please sign in to your account.') }}
                </p>

                @if ($step === 'otp' && filled($maskedDestination))
                    <p class="dw-auth-destination">
                        {{ __('We sent a code to :to', ['to' => $maskedDestination]) }}
                    </p>
                @endif
            </header>

            @if ($step === 'credentials')
                <form
                    wire:submit.prevent="submitCredentials"
                    wire:loading.attr="aria-busy"
                    wire:target="submitCredentials"
                    class="dw-auth-form"
                >
                    {{ $this->credentialsForm }}

                    <x-filament::button
                        type="submit"
                        class="w-full"
                        wire:loading.attr="disabled"
                        wire:target="submitCredentials"
                    >
                        <span wire:loading.remove wire:target="submitCredentials">
                            {{ __('Sign in') }}
                        </span>

                        <span wire:loading wire:target="submitCredentials">
                            {{ __('Signing in...') }}
                        </span>
                    </x-filament::button>
                </form>
            @endif

            @if ($step === 'otp')
                <form
                    wire:submit.prevent="submitOtp"
                    wire:loading.attr="aria-busy"
                    wire:target="submitOtp,resendOtp,backToCredentials"
                    class="dw-auth-form"
                >
                    {{ $this->otpForm }}

                    <x-filament::button
                        type="submit"
                        class="w-full"
                        wire:loading.attr="disabled"
                        wire:target="submitOtp,resendOtp,backToCredentials"
                    >
                        <span wire:loading.remove wire:target="submitOtp">
                            {{ __('Verify') }}
                        </span>

                        <span wire:loading wire:target="submitOtp">
                            {{ __('Verifying...') }}
                        </span>
                    </x-filament::button>

                    <button
                        type="button"
                        wire:click="$set('otpMode', '{{ $otpMode === 'otp' ? 'recovery' : 'otp' }}')"
                        wire:loading.attr="disabled"
                        wire:target="submitOtp,resendOtp,backToCredentials"
                        class="dw-auth-text-action"
                    >
                        {{ $otpMode === 'otp' ? __('Use a recovery code') : __('Use verification code') }}
                    </button>

                    <div class="dw-auth-secondary-actions">
                        <button
                            type="button"
                            wire:click="backToCredentials"
                            wire:loading.attr="disabled"
                            wire:target="submitOtp,resendOtp,backToCredentials"
                            class="dw-auth-secondary-action"
                        >
                            <span aria-hidden="true">←</span>
                            {{ __('Back') }}
                        </button>

                        @if ($canResend)
                            <button
                                type="button"
                                wire:click="resendOtp"
                                wire:loading.attr="disabled"
                                wire:target="submitOtp,resendOtp,backToCredentials"
                                class="dw-auth-secondary-action"
                            >
                                <span wire:loading.remove wire:target="resendOtp">
                                    {{ __('Resend code') }}
                                </span>

                                <span wire:loading wire:target="resendOtp">
                                    {{ __('Sending...') }}
                                </span>
                            </button>
                        @endif
                    </div>
                </form>
            @endif
        </div>
    </section>
</div>
