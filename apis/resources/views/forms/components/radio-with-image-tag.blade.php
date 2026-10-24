<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>

    <ul role="list" class="grid gap-8 xl:grid-cols-3 lg:grid-cols-3 md:grid-cols-3 sm:grid-cols-2 p-4 relative">
        @foreach ($field->getTemplates() as $value => $template)
            <li class="overflow-hidden relative">
                <label class="relative cursor-pointer block">
                    <input
                        id="{{ $getId() }}-{{ $value }}"
                        name="{{ $getId() }}"
                        type="radio"
                        value="{{ $value }}"
                    {{ $applyStateBindingModifiers('wire:model') }}="{{ $getStatePath() }}"
                    class="rb-image"
                    />
                    <span>{{ $template['name'] }}</span>
                    <img
                        src="{{ $template['thumbnail'] }}"
                        alt="{{ $value }}"
                        class="w-full h-[300px] cursor-pointer object-contain"
                    />
                </label>
            </li>
        @endforeach
    </ul>
</x-dynamic-component>
