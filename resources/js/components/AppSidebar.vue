<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChartColumn, History, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import ReportController from '@/actions/App/Http/Controllers/Admin/ReportController';
import {
    create,
    index,
} from '@/actions/App/Http/Controllers/GenerationController';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Generate',
        href: create(),
        icon: Sparkles,
    },
    {
        title: 'History',
        href: index(),
        icon: History,
    },
];

const page = usePage();

const adminNavItems = computed<NavItem[]>(() =>
    page.props.auth.user?.is_admin
        ? [
              {
                  title: 'Admin report',
                  href: ReportController(),
                  icon: ChartColumn,
              },
          ]
        : [],
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="create()" class="px-2">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="[...mainNavItems, ...adminNavItems]" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
