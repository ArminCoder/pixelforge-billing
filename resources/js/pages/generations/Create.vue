<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatUsd } from '@/lib/money';

const props = defineProps<{
    sizes: string[];
    qualities: string[];
    available: number; // credit the user can still spend, in nanodollars
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: '/generations/create' }],
    },
});

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

// The user types one link per line. The server wants a list.
const referenceText = ref('');
watch(referenceText, (text) => {
    form.reference_images = text
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');
});

// ---- Live estimate -------------------------------------------------------

type Estimate = { min: number; max: number };

const estimate = ref<Estimate | null>(null);
const estimateMessage = ref('Write a prompt to see the price.');

/** Laravel checks this token on POST requests. It lives in a cookie. */
function csrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

// Answers can arrive out of order. Only the newest request may update the box.
let latestRequest = 0;

async function loadEstimate() {
    const request = ++latestRequest;

    if (form.prompt.trim() === '') {
        estimate.value = null;
        estimateMessage.value = 'Write a prompt to see the price.';

        return;
    }

    let response: Response | null = null;

    try {
        response = await fetch('/generations/estimate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(form.data()),
        });
    } catch {
        // No answer at all (offline). Handled below like a server error.
    }

    if (request !== latestRequest) {
        return;
    }

    if (response?.ok) {
        estimate.value = await response.json();

        return;
    }

    estimate.value = null;
    estimateMessage.value =
        response?.status === 422
            ? 'Fix the form to see the price.'
            : 'The price is not available right now.';
}

// Wait until the user stops typing for a moment, then ask the server.
const refreshEstimate = useDebounceFn(loadEstimate, 400);

watch(
    () => [form.prompt, form.size, form.quality, form.reference_images],
    refreshEstimate,
    { deep: true },
);

const notEnoughCredit = computed(
    () => estimate.value !== null && estimate.value.max > props.available,
);

// "credits" is not a form field, so read errors as a plain map.
const errors = computed(() => form.errors as Record<string, string>);

// Laravel reports list errors as "reference_images.0", "reference_images.1", ...
const referenceErrors = computed(() =>
    Object.entries(errors.value)
        .filter(([field]) => field.startsWith('reference_images'))
        .map(([, message]) => message),
);

function submit() {
    form.post('/generations');
}
</script>

<template>
    <Head title="Generate" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
        <Heading
            title="Generate an image"
            description="You pay only for images that succeed. Failed images are free."
        />

        <form class="flex flex-col gap-5" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="prompt">Prompt</Label>
                <textarea
                    id="prompt"
                    v-model="form.prompt"
                    rows="5"
                    maxlength="4000"
                    required
                    placeholder="A lighthouse on a cliff at sunrise"
                    class="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs"
                />
                <p class="text-xs text-muted-foreground">
                    {{ form.prompt.length }} / 4000 characters
                </p>
                <InputError :message="form.errors.prompt" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="size">Size</Label>
                    <select
                        id="size"
                        v-model="form.size"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                    >
                        <option v-for="size in sizes" :key="size" :value="size">
                            {{ size }}
                        </option>
                    </select>
                    <InputError :message="form.errors.size" />
                </div>

                <div class="grid gap-2">
                    <Label for="quality">Quality</Label>
                    <select
                        id="quality"
                        v-model="form.quality"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm capitalize shadow-xs"
                    >
                        <option
                            v-for="quality in qualities"
                            :key="quality"
                            :value="quality"
                        >
                            {{ quality }}
                        </option>
                    </select>
                    <InputError :message="form.errors.quality" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="references">Reference image links (optional)</Label>
                <textarea
                    id="references"
                    v-model="referenceText"
                    rows="3"
                    placeholder="One link per line, up to 4"
                    class="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs"
                />
                <InputError
                    v-for="message in referenceErrors"
                    :key="message"
                    :message="message"
                />
            </div>

            <!-- The price box. Always visible, so the cost is never a surprise. -->
            <div class="rounded-lg border p-4" aria-live="polite">
                <p class="text-sm font-medium">Estimated price</p>

                <template v-if="estimate">
                    <p class="mt-1 text-2xl font-semibold">
                        {{ formatUsd(estimate.min) }} to
                        {{ formatUsd(estimate.max) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        The exact price is known when the image is finished. It
                        will be inside this range. We hold
                        {{ formatUsd(estimate.max) }} of your credit until then.
                    </p>
                </template>
                <p v-else class="mt-1 text-sm text-muted-foreground">
                    {{ estimateMessage }}
                </p>

                <p class="mt-3 text-sm">
                    Your available credit:
                    <span class="font-medium">{{ formatUsd(available) }}</span>
                </p>
                <p v-if="notEnoughCredit" class="mt-1 text-sm text-red-600">
                    Not enough credit for this image.
                    <Link href="/generations" class="underline"
                        >Buy credits on the History page.</Link
                    >
                </p>
                <InputError class="mt-1" :message="errors.credits" />
            </div>

            <div>
                <Button
                    type="submit"
                    :disabled="form.processing || notEnoughCredit"
                >
                    {{ form.processing ? 'Sending...' : 'Generate image' }}
                </Button>
            </div>
        </form>
    </div>
</template>
