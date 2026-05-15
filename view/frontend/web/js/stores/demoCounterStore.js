import { writable } from 'svelte/store';

function createDemoCounterStore() {
    const { subscribe, set, update } = writable({
        count: 0,
    });

    return {
        subscribe,
        increment() {
            update((state) => ({
                ...state,
                count: state.count + 1,
            }));
        },
        reset() {
            set({ count: 0 });
        },
    };
}

export const demoCounterStore = createDemoCounterStore();
