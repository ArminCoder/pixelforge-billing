<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { formatUsd } from '@/lib/money';

type Status = 'queued' | 'processing' | 'succeeded' | 'failed';

type Generation = {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    reference_images: number;
    status: Status;
    waiting_for_slot: boolean;
    estimated_price_min: number;
    estimated_price_max: number;
    price: number | null;
    error: string | null;
    image: string | null;
    created_at: string;
};

const props = defineProps<{
    generations: Generation[];
    balance: number;
    reserved: number;
    creditPack: number;
    isAdmin: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: '/generations' }],
    },
});

// ---- Live status ---------------------------------------------------------
// While any image is unfinished, reload the list every 3 seconds.
// When all are finished, stop asking.

const hasUnfinished = computed(() =>
    props.generations.some(
        (generation) =>
            generation.status === 'queued' ||
            generation.status === 'processing',
    ),
);

const { start, stop } = usePoll(
    3000,
    { only: ['generations', 'balance', 'reserved'] },
    { autoStart: false },
);

watch(hasUnfinished, (unfinished) => (unfinished ? start() : stop()), {
    immediate: true,
});

// ---- Text helpers --------------------------------------------------------

const statusStyles: Record<Status, string> = {
    queued: 'bg-neutral-100 text-neutral-700',
    processing: 'bg-blue-100 text-blue-700',
    succeeded: 'bg-green-100 text-green-700',
    failed: 'bg-red-100 text-red-700',
};

function statusHint(generation: Generation): string {
    if (generation.waiting_for_slot) {
        return 'Waiting for a free slot at Pixelforge.';
    }

    switch (generation.status) {
        case 'queued':
            return 'Accepted. It will start in a few seconds.';
        case 'processing':
            return 'Being made. This usually takes under a minute.';
        case 'succeeded':
            return 'Done.';
        case 'failed':
            return generation.error ?? 'Failed. You were not charged.';
    }
}

function buyCredits() {
    router.post('/credits', {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="History" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="History"
                description="Every image you asked for, with its status and price."
            />
            <div class="flex items-center gap-2">
                <Link
                    v-if="isAdmin"
                    href="/admin/report"
                    class="text-sm underline"
                    >Admin report</Link
                >
                <Button as-child>
                    <Link href="/generations/create">New image</Link>
                </Button>
            </div>
        </div>

        <!-- Credit -->
        <div
            class="flex flex-wrap items-center justify-between gap-4 rounded-lg border p-4"
        >
            <div>
                <p class="text-sm text-muted-foreground">Credit balance</p>
                <p class="text-2xl font-semibold">{{ formatUsd(balance) }}</p>
                <p v-if="reserved > 0" class="text-xs text-muted-foreground">
                    {{ formatUsd(reserved) }} is held for images still being
                    made. Unused credit is released when they finish.
                </p>
            </div>
            <Button variant="outline" @click="buyCredits">
                Buy {{ formatUsd(creditPack) }} credits (fake)
            </Button>
        </div>

        <p
            v-if="generations.length === 0"
            class="text-sm text-muted-foreground"
        >
            No images yet.
        </p>

        <ul class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <li
                v-for="generation in generations"
                :key="generation.id"
                class="flex flex-col gap-3 rounded-lg border p-4"
            >
                <div
                    class="flex aspect-square items-center justify-center overflow-hidden rounded-md bg-muted"
                >
                    <img
                        v-if="generation.image"
                        :src="generation.image"
                        :alt="generation.prompt"
                        class="h-full w-full object-contain"
                    />
                    <span v-else class="text-sm text-muted-foreground">
                        {{
                            generation.status === 'failed'
                                ? 'No image'
                                : 'Not ready yet'
                        }}
                    </span>
                </div>

                <div class="flex items-center gap-2">
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                        :class="statusStyles[generation.status]"
                    >
                        {{ generation.status }}
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ generation.size }}, {{ generation.quality }}
                        <template v-if="generation.reference_images > 0">
                            , {{ generation.reference_images }} reference
                            image(s)
                        </template>
                    </span>
                </div>

                <p
                    class="text-sm"
                    :class="{ 'text-red-600': generation.status === 'failed' }"
                >
                    {{ statusHint(generation) }}
                </p>

                <p class="line-clamp-3 text-sm text-muted-foreground">
                    {{ generation.prompt }}
                </p>

                <dl
                    class="mt-auto grid grid-cols-2 gap-2 border-t pt-3 text-sm"
                >
                    <div>
                        <dt class="text-xs text-muted-foreground">Estimate</dt>
                        <dd>
                            {{ formatUsd(generation.estimated_price_min) }} to
                            {{ formatUsd(generation.estimated_price_max) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Charged</dt>
                        <dd class="font-medium">
                            {{
                                generation.price === null
                                    ? 'Not known yet'
                                    : formatUsd(generation.price)
                            }}
                        </dd>
                    </div>
                </dl>
            </li>
        </ul>
    </div>
</template>
