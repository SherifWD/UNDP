<script setup>
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../stores/auth';
import LanguageSwitch from '../components/LanguageSwitch.vue';

const router = useRouter();
const auth = useAuthStore();
const { t } = useI18n();

const form = reactive({
    country_code: '+218',
    phone: '',
});

const countries = [
    { code: '+218', flag: '🇱🇾', label: 'Libya' },
    { code: '+20', flag: '🇪🇬', label: 'Egypt' },
    { code: '+216', flag: '🇹🇳', label: 'Tunisia' },
    { code: '+971', flag: '🇦🇪', label: 'UAE' },
];

const error = ref('');
const loading = ref(false);

const phoneDigits = computed(() => form.phone.replace(/\D/g, ''));
const canContinue = computed(() => phoneDigits.value.length >= 8 && phoneDigits.value.length <= 15);
const phoneValidationMessage = computed(() => {
    if (!phoneDigits.value.length) {
        return '';
    }

    if (phoneDigits.value.length < 8 || phoneDigits.value.length > 15) {
        return t('login.invalidPhone');
    }

    return '';
});

const submit = async () => {
    error.value = '';

    if (!canContinue.value) {
        error.value = t('login.invalidPhone');
        return;
    }

    loading.value = true;

    try {
        const result = await auth.requestOtp({
            country_code: form.country_code,
            phone: phoneDigits.value,
        });

        await router.push({
            name: 'otp',
            query: {
                country_code: form.country_code,
                digits: result.code_digits || 6,
                phone: phoneDigits.value,
            },
        });
    } catch (err) {
        error.value = err.response?.data?.message || t('login.unableToSendOtp');
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <main class="auth-page">
        <section class="auth-card">
            <div class="auth-card__header">
                <h1>{{ t('login.title') }}</h1>
                <p>{{ t('login.subtitle') }}</p>
            </div>

            <LanguageSwitch />

            <label class="field">
                <span>{{ t('login.countryCode') }}</span>
                <select v-model="form.country_code">
                    <option v-for="item in countries" :key="item.code" :value="item.code">
                        {{ item.flag }} {{ item.code }} - {{ item.label }}
                    </option>
                </select>
            </label>

            <label class="field">
                <span>{{ t('login.phone') }}</span>
                <input
                    v-model="form.phone"
                    type="tel"
                    inputmode="numeric"
                    autocomplete="tel"
                    placeholder="9xxxxxxxx"
                    @input="form.phone = form.phone.replace(/[^0-9]/g, '')"
                >
            </label>

            <p class="field-error" v-if="phoneValidationMessage">{{ phoneValidationMessage }}</p>
            <p class="field-error" v-if="error">{{ error }}</p>

            <button class="btn btn--primary" :disabled="!canContinue || loading" @click="submit">
                {{ loading ? t('common.loading') : t('login.continue') }}
            </button>
        </section>
    </main>
</template>
