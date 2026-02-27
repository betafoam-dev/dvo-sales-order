<!-- BoomBox.vue - complete rewrite of the slow parts -->
<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { Check, ChevronsUpDown, X } from 'lucide-vue-next'

const props = defineProps({
    items:                { type: Array, required: true },
    existingValue:        { type: [Number, String, Object, Array], default: null },
    labelField:           { type: String, default: 'name' },
    descriptionField:     { type: String, default: null },
    secondDescriptionField: { type: String, default: null },
    nameDescriptionField: { type: String, default: null },
    searchFields:         { type: Array, default: () => ['name'] },
    placeholder:          { type: String, default: '- ' },
    multiple:             { type: Boolean, default: false },
    readonly:             { type: Boolean, default: false },
    disabled:             { type: Boolean, default: false },
})

const emit = defineEmits(['change'])

const open         = ref(false)
const searchValue  = ref('')
const selectedItem = ref(null)
const selectedItems = ref([])
const searchInput  = ref(null)

// Restore existing value
watch(() => props.existingValue, (newValue) => {
    if (props.multiple) {
        if (newValue && Array.isArray(newValue)) {
            selectedItems.value = newValue
                .map(v => props.items.find(i => i.id === v || i.id === Number(v) || i[props.labelField] === v))
                .filter(Boolean)
        } else {
            selectedItems.value = []
        }
    } else {
        selectedItem.value = newValue
            ? props.items.find(i => i.id === newValue || i.id === Number(newValue) || i[props.labelField] === newValue) ?? null
            : null
    }
}, { immediate: true })

// Also re-match when items list changes (lazy load case)
watch(() => props.items, () => {
    if (!props.multiple && props.existingValue && !selectedItem.value) {
        selectedItem.value = props.items.find(i =>
            i.id === props.existingValue ||
            i.id === Number(props.existingValue) ||
            i[props.labelField] === props.existingValue
        ) ?? null
    }
})

const filteredItems = computed(() => {
    const q = searchValue.value.trim().toLowerCase()
    return props.items.filter(item => {
        if (props.multiple && selectedItems.value.some(s => s.id === item.id)) return false
        if (!q) return true
        return props.searchFields.some(field => {
            const val = item[field]
            return val != null && String(val).toLowerCase().includes(q)
        })
    })
})

const displayText = computed(() => {
    if (props.multiple) {
        if (!selectedItems.value.length) return props.placeholder
        return selectedItems.value.length === 1
            ? selectedItems.value[0][props.labelField]
            : `${selectedItems.value.length} selected`
    }
    return selectedItem.value ? selectedItem.value[props.labelField] : props.placeholder
})

const openDropdown = async () => {
    if (props.readonly || props.disabled) return
    open.value = true
    searchValue.value = ''
    await nextTick()
    searchInput.value?.focus()
}

const closeDropdown = () => {
    open.value = false
    searchValue.value = ''
}

const selectItem = (item) => {
    if (props.readonly || props.disabled) return
    if (props.multiple) {
        const idx = selectedItems.value.findIndex(s => s.id === item.id)
        if (idx > -1) selectedItems.value.splice(idx, 1)
        else selectedItems.value.push(item)
        emit('change', selectedItems.value)
    } else {
        selectedItem.value = item
        emit('change', item)
        closeDropdown()
    }
}

const clearSingle = () => {
    selectedItem.value = null
    emit('change', null)
}

const removeItem = (itemToRemove) => {
    selectedItems.value = selectedItems.value.filter(i => i.id !== itemToRemove.id)
    emit('change', selectedItems.value)
}

const onClickOutside = (e) => {
    if (!e.target.closest('.boombox-wrapper')) closeDropdown()
}
</script>

<template>
    <div class="boombox-wrapper relative w-full" v-click-outside="closeDropdown">

        <!-- Trigger Button -->
        <button
            type="button"
            @click="open ? closeDropdown() : openDropdown()"
            :disabled="disabled"
            class="flex items-center justify-between w-full px-3 py-2 text-sm bg-white border border-black rounded hover:bg-gray-50 focus:outline-none"
            :class="{
                'opacity-60 cursor-not-allowed bg-gray-50': readonly || disabled,
            }"
        >
            <!-- Single mode display -->
            <template v-if="!multiple">
                <span class="truncate" :class="selectedItem ? 'text-black' : 'text-gray-400 italic'">
                    {{ displayText }}
                </span>
                <span class="flex items-center gap-1 shrink-0">
                    <X
                        v-if="selectedItem && !readonly && !disabled"
                        class="w-3 h-3 text-gray-400 hover:text-red-500"
                        @click.stop="clearSingle"
                    />
                    <ChevronsUpDown class="w-4 h-4 opacity-50" />
                </span>
            </template>

            <!-- Multiple mode display -->
            <template v-else>
                <div class="flex flex-wrap gap-1 overflow-hidden">
                    <template v-if="selectedItems.length">
                        <span
                            v-for="item in selectedItems.slice(0, 5)"
                            :key="item.id"
                            class="flex items-center gap-1 px-1.5 py-0.5 text-xs bg-gray-100 rounded"
                        >
                            {{ item[labelField] }}
                            <X class="w-3 h-3 cursor-pointer hover:text-red-500" @click.stop="removeItem(item)" />
                        </span>
                        <span v-if="selectedItems.length > 5" class="text-xs text-gray-500">
                            +{{ selectedItems.length - 5 }} more
                        </span>
                    </template>
                    <span v-else class="text-gray-400 italic text-sm">{{ placeholder }}</span>
                </div>
                <ChevronsUpDown class="w-4 h-4 opacity-50 shrink-0 ml-2" />
            </template>
        </button>

        <!-- Dropdown -->
        <div
            v-if="open && !readonly && !disabled"
            class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded shadow-lg"
        >
            <!-- Search input -->
            <div class="p-2 border-b border-gray-100">
                <input
                    ref="searchInput"
                    v-model="searchValue"
                    type="text"
                    placeholder="Search..."
                    class="w-full px-2 py-1.5 text-sm border border-gray-200 rounded focus:outline-none focus:border-blue-400"
                    @keydown.escape="closeDropdown"
                />
            </div>

            <!-- List -->
            <ul class="max-h-60 overflow-y-auto">
                <li v-if="!filteredItems.length" class="px-3 py-2 text-sm text-gray-400 italic">
                    No results found.
                </li>
                <li
                    v-for="item in filteredItems"
                    :key="item.id"
                    @mousedown.prevent="selectItem(item)"
                    class="flex items-center gap-2 px-3 py-2 text-sm cursor-pointer hover:bg-blue-50"
                    :class="{ 'bg-blue-50 font-medium': multiple && selectedItems.some(s => s.id === item.id) }"
                >
                    <Check
                        v-if="multiple && selectedItems.some(s => s.id === item.id)"
                        class="w-4 h-4 text-blue-500 shrink-0"
                    />
                    <div class="flex flex-col">
                        <span>{{ item[labelField] }}</span>
                        <span v-if="nameDescriptionField && item[nameDescriptionField]" class="text-xs text-gray-400">
                            {{ item[nameDescriptionField] }}
                            <template v-if="descriptionField && item[descriptionField]">
                                : {{ item[descriptionField] }}
                            </template>
                        </span>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>