export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type Generation = {
    id: number;
    status: GenerationStatus;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_charge_nano: number;
    max_charge_nano: number;
    charge_nano: number | null;
    image_url: string | null;
    image_expired: boolean;
    image_url_expires_at: string | null;
    error_code: string | null;
    error_message: string | null;
    created_at: string;
    completed_at: string | null;
};

export type Quote = {
    estimated_charge_nano: number;
    max_charge_nano: number;
    available_nano: number;
    tokens: {
        input_text: { estimated: number; max: number };
        input_image: number;
        output: number;
        reasoning: { estimated: number; max: number };
    };
};

export type Credits = {
    balance_nano: number;
    available_nano: number;
};

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        from: number | null;
        to: number | null;
        total: number;
        last_page: number;
    };
};
