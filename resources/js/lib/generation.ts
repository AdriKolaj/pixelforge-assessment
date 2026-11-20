import type { Generation, GenerationStatus } from '@/types';

const ACTIVE: GenerationStatus[] = ['pending', 'queued', 'processing'];

export function isActive(generation: Pick<Generation, 'status'>): boolean {
    return ACTIVE.includes(generation.status);
}

export const sizeLabels: Record<string, string> = {
    '1024x1024': '1:1',
    '1536x1024': '3:2',
    '1024x1536': '2:3',
};

export function aspectRatio(size: string): string {
    const [width, height] = size.split('x');

    return `${width} / ${height}`;
}

export function capitalize(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}
