<div class="w-full max-w-md mx-auto">

    <div class="fi-auth-card">
        {{-- HEADER --}}
        <div class="text-center mb-8 space-y-2">
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">
                {{ $step === 'otp' ? __('Verify your account') : __('Sign in') }}
            </h1>

            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ $step === 'otp'
                    ? __('Enter the verification code to continue.')
                    : __('Welcome back. Please sign in to your account.') }}
            </p>

            @if ($step === 'otp' && filled($maskedDestination))
                <p class="text-xs text-zinc-400">
                    {{ __('We sent a code to :to', ['to' => $maskedDestination]) }}
                </p>
            @endif
        </div>

        {{-- STEP: CREDENTIALS --}}
        @if ($step === 'credentials')
            <form wire:submit.prevent="submitCredentials" class="space-y-6">

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

        {{-- STEP: OTP --}}
        @if ($step === 'otp')
            <form wire:submit.prevent="submitOtp" class="space-y-6">

                {{ $this->otpForm }}

                <x-filament::button
                    type="submit"
                    class="w-full"
                    wire:loading.attr="disabled"
                    wire:target="submitOtp"
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
                    class="text-sm text-primary underline"
                >
                    {{ $otpMode === 'otp' ? __('Use a recovery code') : __('Use verification code') }}
                </button>

                <div class="flex items-center justify-between pt-3 text-sm">

                    <button
                        type="button"
                        wire:click="backToCredentials"
                        class="text-zinc-500 hover:text-zinc-900 dark:hover:text-white"
                    >
                        ← {{ __('Back') }}
                    </button>

                    @if ($canResend)
                        <button
                            type="button"
                            wire:click="resendOtp"
                            wire:loading.attr="disabled"
                            wire:target="resendOtp"
                            class="text-zinc-500 hover:text-zinc-900 dark:hover:text-white"
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

</div>
