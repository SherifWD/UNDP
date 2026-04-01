<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../stores/auth';
import { useUiStore } from '../stores/ui';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const ui = useUiStore();
const { t, locale } = useI18n();

const normalizeOtpLength = (value) => {
    const parsed = Number.parseInt(String(value ?? ''), 10);

    if (Number.isNaN(parsed)) {
        return 6;
    }

    return Math.max(4, Math.min(parsed, 6));
};

const countryCode = computed(() => route.query.country_code || auth.otpContext?.country_code || '+218');
const otpLength = computed(() => normalizeOtpLength(route.query.digits || auth.otpContext?.otp_digits || 6));
const phone = computed(() => route.query.phone || auth.otpContext?.phone || '');
const maskedPhone = computed(() => auth.otpContext?.masked_phone || `${countryCode.value} ****${String(phone.value).slice(-2)}`);

const digits = ref(Array.from({ length: otpLength.value }, () => ''));
const inputs = ref([]);
const error = ref('');
const loading = ref(false);
const countdown = ref(60);
let interval;

const code = computed(() => digits.value.join(''));
const canVerify = computed(() => code.value.length === otpLength.value && !digits.value.includes(''));

const focusInput = (index) => {
    inputs.value[index]?.focus();
};

const handleInput = (index, event) => {
    const value = event.target.value.replace(/\D/g, '').slice(-1);
    digits.value[index] = value;

    if (value && index < digits.value.length - 1) {
        focusInput(index + 1);
    }
};

const handleKeydown = (index, event) => {
    if (event.key === 'Backspace' && !digits.value[index] && index > 0) {
        focusInput(index - 1);
    }
};

watch(otpLength, (length) => {
    if (digits.value.length === length) {
        return;
    }

    digits.value = Array.from({ length }, (_, index) => digits.value[index] || '');
});

const startCountdown = () => {
    clearInterval(interval);
    countdown.value = 60;

    interval = setInterval(() => {
        if (countdown.value <= 0) {
            clearInterval(interval);
            return;
        }

        countdown.value -= 1;
    }, 1000);
};

const verify = async () => {
    error.value = '';

    if (!canVerify.value) return;

    loading.value = true;

    try {
        const result = await auth.verifyOtp({
            country_code: countryCode.value,
            phone: String(phone.value),
            code: code.value,
            preferred_locale: locale.value,
        });

        if (result?.is_returning_user) {
            ui.pushToast(t('otp.welcomeBack'), 'success');
        } else {
            ui.pushToast(t('otp.accountVerified'), 'success');
        }

        await router.push({ name: 'home' });
    } catch (err) {
        error.value = err.response?.data?.message || t('otp.invalidCode');
    } finally {
        loading.value = false;
    }
};

const resend = async () => {
    if (countdown.value > 0) return;

    error.value = '';

    try {
        await auth.requestOtp({
            country_code: countryCode.value,
            phone: String(phone.value),
        });
        startCountdown();
    } catch (err) {
        error.value = err.response?.data?.message || t('otp.unableToResend');
    }
};

onMounted(() => {
    if (!phone.value) {
        router.replace({ name: 'login' });
        return;
    }

    startCountdown();
    focusInput(0);
});

onBeforeUnmount(() => {
    clearInterval(interval);
});
</script>

<template>
    <main class="auth-page">
        <section class="auth-card">
            <div class="auth-card__header">
                <h1>{{ t('otp.title') }}</h1>
                <p>{{ t('otp.subtitle') }} <strong>{{ maskedPhone }}</strong></p>
            </div>

            <div class="otp-grid" dir="ltr">
                <input
                    v-for="(_, index) in digits"
                    :key="index"
                    :ref="(el) => inputs[index] = el"
                    type="text"
                    class="otp-input"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    name="otp_code"
                    maxlength="1"
                    dir="ltr"
                    :value="digits[index]"
                    @input="handleInput(index, $event)"
                    @keydown="handleKeydown(index, $event)"
                >
            </div>

            <p class="field-error" v-if="error">{{ error }}</p>

            <button class="btn btn--primary" :disabled="!canVerify || loading" @click="verify">
                {{ loading ? t('common.loading') : t('otp.verify') }}
            </button>

            <div class="otp-actions">
                <button class="btn btn--ghost" :disabled="countdown > 0" @click="resend">
                    {{ t('otp.resend') }} <span v-if="countdown > 0">({{ countdown }}s)</span>
                </button>
                <button class="btn btn--ghost" @click="router.push({ name: 'login' })">
                    {{ t('otp.changeNumber') }}
                </button>
            </div>
        </section>
    </main>
</template>
