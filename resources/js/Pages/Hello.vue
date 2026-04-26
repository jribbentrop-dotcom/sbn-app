<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <div>
        <Head title="Hello World" />
        <div class="min-h-screen bg-gray-100 flex items-center justify-center">
            <div class="bg-white p-8 rounded-lg shadow-lg text-center">
                <h1 class="text-4xl font-bold text-blue-600 mb-4">Hello, World!</h1>
                <p class="text-gray-600">This is an Inertia page styled with Tailwind CSS.</p>
            </div>
        </div>
    </div>
</template>
