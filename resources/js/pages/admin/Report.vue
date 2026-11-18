<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { formatUsd } from '@/lib/money';

type Row = {
    label: string;
    images: number;
    paid: number;
    cost: number;
    profit: number;
};

defineProps<{
    byDay: Row[];
    byUser: Row[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin report', href: '/admin/report' }],
    },
});
</script>

<template>
    <Head title="Admin report" />

    <div class="flex flex-col gap-8 p-4">
        <Heading
            title="Admin report"
            description="Only images that succeeded are counted. Days are UTC days on which the image finished, the same as Pixelforge's usage report."
        />

        <section
            v-for="table in [
                { title: 'Per day (UTC)', first: 'Day', rows: byDay },
                { title: 'Per user', first: 'User', rows: byUser },
            ]"
            :key="table.title"
        >
            <h2 class="mb-2 text-lg font-medium">{{ table.title }}</h2>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-muted/50">
                        <tr>
                            <th class="p-3">{{ table.first }}</th>
                            <th class="p-3 text-right">Images</th>
                            <th class="p-3 text-right">Users paid</th>
                            <th class="p-3 text-right">Pixelforge cost</th>
                            <th class="p-3 text-right">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in table.rows"
                            :key="row.label"
                            class="border-b last:border-0"
                        >
                            <td class="p-3">{{ row.label }}</td>
                            <td class="p-3 text-right">{{ row.images }}</td>
                            <td class="p-3 text-right">
                                {{ formatUsd(row.paid) }}
                            </td>
                            <td class="p-3 text-right">
                                {{ formatUsd(row.cost) }}
                            </td>
                            <td class="p-3 text-right font-medium">
                                {{ formatUsd(row.profit) }}
                            </td>
                        </tr>
                        <tr v-if="table.rows.length === 0">
                            <td class="p-3 text-muted-foreground" colspan="5">
                                No finished images yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
