<script setup>
import { Head, useForm } from '@statamic/cms/inertia';
import { Header, Button, Card, Input, Description } from '@statamic/cms/ui';

const props = defineProps({
    collections: { type: Array, default: () => [] },
    confirmUrl: { type: String, required: true },
});

const form = useForm({
    collection: null,
    file: null,
    identifier: 'id',
    delimiter: ',',
});

function submit() {
    form.post(props.confirmUrl, {
        forceFormData: true,
    });
}

function setFile(event) {
    form.file = event.target.files[0] ?? null;
}
</script>

<template>
    <div class="max-w-xl mx-auto">
        <Head :title="__('Resrv Data import')" />

        <form @submit.prevent="submit" enctype="multipart/form-data">
            <Header :title="__('Import availability')">
                <Button variant="primary" type="submit" :loading="form.processing">
                    {{ __('Continue') }}
                </Button>
            </Header>

            <Card class="p-4 lg:px-8 lg:py-6">
                <div class="mb-5">
                    <label class="font-bold text-base mb-2 block">{{ __('Collection') }}</label>
                    <div v-if="collections.length === 0" class="text-2xs text-gray-500 mt-1">
                        {{ __('No collections with Resrv availability fields.') }}
                    </div>
                    <template v-else>
                        <div
                            v-for="collection in collections"
                            :key="collection.handle"
                            class="flex mb-2 items-center"
                        >
                            <input
                                :id="`collection-${collection.handle}`"
                                v-model="form.collection"
                                type="radio"
                                name="collection"
                                :value="collection.handle"
                            >
                            <label :for="`collection-${collection.handle}`" class="ml-2">
                                {{ collection.title }}
                            </label>
                        </div>
                    </template>
                    <div v-if="form.errors.collection" class="mt-1 text-red-600 text-sm">
                        {{ form.errors.collection }}
                    </div>
                </div>

                <div class="mb-5">
                    <label for="file" class="font-bold text-base mb-2 block">{{ __('File') }}</label>
                    <input id="file" name="file" type="file" class="input-text" @change="setFile" />
                    <Description>
                        {{ __('A CSV file, please check the docs for the correct format.') }}
                    </Description>
                    <div v-if="form.errors.file" class="mt-1 text-red-600 text-sm">
                        {{ form.errors.file }}
                    </div>
                </div>

                <div class="mb-5">
                    <label for="identifier" class="font-bold text-base mb-2 block">{{ __('Identifier') }}</label>
                    <Input id="identifier" v-model="form.identifier" name="identifier" placeholder="id" />
                    <Description>
                        {{ __("The unique ID for the import. It is usually the Statamic entry's ID") }}
                    </Description>
                    <div v-if="form.errors.identifier" class="mt-1 text-red-600 text-sm">
                        {{ form.errors.identifier }}
                    </div>
                </div>

                <div class="mb-5">
                    <label for="delimiter" class="font-bold text-base mb-2 block">{{ __('Delimiter') }}</label>
                    <Input id="delimiter" v-model="form.delimiter" name="delimiter" placeholder="," />
                    <Description>
                        {{ __('Defaults to ",". Is usually one of , ; |') }}
                    </Description>
                    <div v-if="form.errors.delimiter" class="mt-1 text-red-600 text-sm">
                        {{ form.errors.delimiter }}
                    </div>
                </div>
            </Card>
        </form>
    </div>
</template>
