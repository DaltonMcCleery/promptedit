<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck } from '@lucide/vue';
import ReportController from '@/actions/App/Http/Controllers/Admin/ReportController';
import Money from '@/components/Money.vue';
import { exactUsd } from '@/lib/money';

/** Money is integer nanodollars. Differences are ours − Pixelforge's. */
type Totals = {
    generations: number;
    charge_nanos: number;
    provider_cost_nanos: number;
    profit_nanos: number;
};

type Compared = Totals & {
    pixelforge_cost_nanos: number | null;
    difference_nanos: number | null;
};

type Day = Compared & { date: string };

type UserRow = Totals & { id: number; name: string; email: string };

defineProps<{
    start_date: string;
    end_date: string;
    comparison_available: boolean;
    days: Day[];
    users: UserRow[];
    totals: Compared;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin report', href: ReportController() }],
    },
});

function signedDifference(nanos: number): string {
    return nanos > 0 ? `+${exactUsd(nanos)}` : exactUsd(nanos);
}

function formatDate(date: string): string {
    return new Date(`${date}T00:00:00Z`).toLocaleDateString(undefined, {
        timeZone: 'UTC',
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    });
}

const th = 'px-3 py-2 font-medium';
const td = 'px-3 py-2 text-right tabular-nums';
</script>

<template>
    <Head title="Admin report" />

    <div class="flex flex-1 flex-col gap-8 p-4">
        <div>
            <h1 class="text-xl font-semibold">Admin report</h1>
            <p class="text-sm text-muted-foreground">
                {{ formatDate(start_date) }} – {{ formatDate(end_date) }}, by
                the UTC day each generation completed (how Pixelforge bills).
                Only succeeded generations are billed. Amounts are exact, in US
                dollars.
            </p>
        </div>

        <div
            v-if="!comparison_available"
            role="status"
            class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
            <CircleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            Comparison with Pixelforge is unavailable right now, because GET
            /v1/usage couldn't be reached. These are the app's own numbers.
        </div>

        <section aria-labelledby="by-day" class="flex flex-col gap-2">
            <h2 id="by-day" class="font-medium">By day</h2>
            <div
                class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th scope="col" :class="th">Date (UTC)</th>
                            <th scope="col" :class="[th, 'text-right']">
                                Generations
                            </th>
                            <th scope="col" :class="[th, 'text-right']">
                                Credit spend
                            </th>
                            <th scope="col" :class="[th, 'text-right']">
                                Provider cost
                            </th>
                            <th scope="col" :class="[th, 'text-right']">
                                Pixelforge billed
                            </th>
                            <th scope="col" :class="[th, 'text-right']">
                                Match
                            </th>
                            <th scope="col" :class="[th, 'text-right']">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="day in days"
                            :key="day.date"
                            class="border-t border-sidebar-border/50"
                            :class="{
                                'text-muted-foreground':
                                    day.generations === 0 &&
                                    day.pixelforge_cost_nanos === 0,
                            }"
                            :data-test="`day-${day.date}`"
                        >
                            <th
                                scope="row"
                                :class="[
                                    th,
                                    'text-left font-normal whitespace-nowrap',
                                ]"
                            >
                                {{ formatDate(day.date) }}
                            </th>
                            <td :class="td">{{ day.generations }}</td>
                            <td :class="td">
                                <Money :nanos="day.charge_nanos" exact />
                            </td>
                            <td :class="td">
                                <Money :nanos="day.provider_cost_nanos" exact />
                            </td>
                            <td :class="td">
                                <Money
                                    v-if="day.pixelforge_cost_nanos !== null"
                                    :nanos="day.pixelforge_cost_nanos"
                                    exact
                                />
                                <span v-else>—</span>
                            </td>
                            <td :class="td">
                                <span v-if="day.difference_nanos === null"
                                    >—</span
                                >
                                <span
                                    v-else-if="day.difference_nanos === 0"
                                    class="inline-flex items-center gap-1 text-green-700 dark:text-green-400"
                                >
                                    <CircleCheck
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Matches
                                </span>
                                <span
                                    v-else
                                    class="font-medium text-red-700 dark:text-red-400"
                                >
                                    {{ signedDifference(day.difference_nanos) }}
                                </span>
                            </td>
                            <td :class="td">
                                <Money :nanos="day.profit_nanos" exact />
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 border-sidebar-border font-medium">
                        <tr>
                            <th scope="row" :class="[th, 'text-left']">
                                Total
                            </th>
                            <td :class="td">{{ totals.generations }}</td>
                            <td :class="td">
                                <Money :nanos="totals.charge_nanos" exact />
                            </td>
                            <td :class="td">
                                <Money
                                    :nanos="totals.provider_cost_nanos"
                                    exact
                                />
                            </td>
                            <td :class="td">
                                <Money
                                    v-if="totals.pixelforge_cost_nanos !== null"
                                    :nanos="totals.pixelforge_cost_nanos"
                                    exact
                                />
                                <span v-else>—</span>
                            </td>
                            <td :class="td">
                                <span v-if="totals.difference_nanos === null"
                                    >—</span
                                >
                                <span
                                    v-else-if="totals.difference_nanos === 0"
                                    class="text-green-700 dark:text-green-400"
                                    >Matches</span
                                >
                                <span
                                    v-else
                                    class="text-red-700 dark:text-red-400"
                                    >{{
                                        signedDifference(
                                            totals.difference_nanos,
                                        )
                                    }}</span
                                >
                            </td>
                            <td :class="td">
                                <Money :nanos="totals.profit_nanos" exact />
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <section aria-labelledby="by-user" class="flex flex-col gap-2">
            <h2 id="by-user" class="font-medium">By user</h2>
            <div
                class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th scope="col" :class="th">User</th>
                            <th scope="col" :class="[th, 'text-right']">
                                Generations
                            </th>
                            <th scope="col" :class="[th, 'text-right']">
                                Credit spend
                            </th>
                            <th scope="col" :class="[th, 'text-right']">
                                Provider cost
                            </th>
                            <th scope="col" :class="[th, 'text-right']">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="users.length === 0">
                            <td
                                colspan="5"
                                class="px-3 py-6 text-center text-muted-foreground"
                            >
                                No billed generations in the last 30 days.
                            </td>
                        </tr>
                        <tr
                            v-for="user in users"
                            :key="user.id"
                            class="border-t border-sidebar-border/50"
                        >
                            <th
                                scope="row"
                                :class="[th, 'text-left font-normal']"
                            >
                                {{ user.name }}
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ user.email }}</span
                                >
                            </th>
                            <td :class="td">{{ user.generations }}</td>
                            <td :class="td">
                                <Money :nanos="user.charge_nanos" exact />
                            </td>
                            <td :class="td">
                                <Money
                                    :nanos="user.provider_cost_nanos"
                                    exact
                                />
                            </td>
                            <td :class="td">
                                <Money :nanos="user.profit_nanos" exact />
                            </td>
                        </tr>
                    </tbody>
                    <tfoot
                        v-if="users.length > 0"
                        class="border-t-2 border-sidebar-border font-medium"
                    >
                        <tr>
                            <th scope="row" :class="[th, 'text-left']">
                                Total
                            </th>
                            <td :class="td">{{ totals.generations }}</td>
                            <td :class="td">
                                <Money :nanos="totals.charge_nanos" exact />
                            </td>
                            <td :class="td">
                                <Money
                                    :nanos="totals.provider_cost_nanos"
                                    exact
                                />
                            </td>
                            <td :class="td">
                                <Money :nanos="totals.profit_nanos" exact />
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    </div>
</template>
