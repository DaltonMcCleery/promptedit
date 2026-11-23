import type { Quality, Size } from '@/lib/pricing';

export type GenerationStatus =
    | 'submitting'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type PendingReason = 'waiting_for_slot' | 'unreachable';

/** App\Http\Resources\GenerationResource. Money is integer nanodollars. */
export type Generation = {
    id: string;
    prompt: string;
    size: Size;
    quality: Quality;
    reference_count: number;
    status: GenerationStatus;
    pending_reason: PendingReason | null;
    is_final: boolean;
    estimate_min_nanos: number;
    estimate_max_nanos: number;
    charge_nanos: number | null;
    failure_reason: string | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    created_at: string;
};

export type Paginated<T> = {
    data: T[];
    links: { prev: string | null; next: string | null };
    meta: {
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};
