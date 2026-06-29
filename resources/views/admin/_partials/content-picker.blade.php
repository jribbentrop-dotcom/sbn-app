{{--
  Reusable search + chips multi-picker for admin forms.

  Replaces a long <select multiple> (unusable at 265 chord diagrams) with a
  type-to-filter box + removable chips. Alpine-based to match the house pattern
  in admin/courses/_form.blade.php; reuses .sbn-tag-chip / .sbn-tag-remove CSS.
  Alpine is already loaded globally in layouts/admin.blade.php.

  Params:
    $field    string  form field name; posts as $field[] (array of ids)
    $label    string  visible label
    $options  iterable of objects exposing ->id and a display field
    $labelKey string  property on each option to show (default 'title')
    $selected array   pre-selected ids
    $hint     string  optional helper line
--}}
@php
    $labelKey = $labelKey ?? 'title';
    $picker   = 'cp_' . $field; // unique-ish Alpine component id
    $opts     = collect($options)->map(fn ($o) => ['id' => $o->id, 'label' => (string) $o->{$labelKey}])->values();
    $selectedIds = array_values(array_map('intval', old($field, $selected ?? [])));
    // Data lives in <script type="application/json"> rather than inline in x-data,
    // so apostrophes in titles (e.g. "Ain't") can't close the attribute. Only </
    // needs escaping inside a script tag — JSON_HEX_TAG handles it.
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
@endphp

<script type="application/json" id="{{ $picker }}-data">{!! json_encode(['all' => $opts, 'selected' => $selectedIds], $jsonFlags) !!}</script>
<div class="sbn-form-group"
     x-data='{
        ...JSON.parse(document.getElementById("{{ $picker }}-data").textContent),
        q: "",
        open: false,
        get available() {
            const term = this.q.trim().toLowerCase();
            return this.all.filter(o =>
                !this.selected.includes(o.id) &&
                (term === "" || o.label.toLowerCase().includes(term))
            ).slice(0, 50);
        },
        labelFor(id) { const o = this.all.find(x => x.id === id); return o ? o.label : id; },
        add(id) { if (!this.selected.includes(id)) this.selected.push(id); this.q = ""; this.open = false; },
        remove(id) { this.selected = this.selected.filter(x => x !== id); },
     }'>
    <label>{{ $label }}</label>

    {{-- chosen ids posted as $field[] --}}
    <template x-for="id in selected" :key="id">
        <input type="hidden" name="{{ $field }}[]" :value="id">
    </template>

    {{-- selected chips --}}
    <div class="sbn-tags-active" style="min-height:0;margin-bottom:8px;">
        <template x-if="selected.length === 0">
            <span class="sbn-tags-none">None selected</span>
        </template>
        <template x-for="id in selected" :key="'chip-'+id">
            <span class="sbn-tag-chip">
                <span x-text="labelFor(id)"></span>
                <button type="button" class="sbn-tag-remove" @click="remove(id)">×</button>
            </span>
        </template>
    </div>

    {{-- search box + results dropdown --}}
    <div style="position:relative;">
        <input type="text" class="sbn-search-input" placeholder="Search to add…"
               style="padding-left:14px;"
               x-model="q"
               @focus="open = true"
               @click="open = true"
               @keydown.escape="open = false"
               @keydown.enter.prevent="if (available.length) add(available[0].id)">
        <div x-show="open && available.length" x-cloak @click.outside="open = false"
             style="position:absolute;z-index:20;left:0;right:0;max-height:220px;overflow:auto;
                    background:var(--clr-surface-2);border:1px solid var(--clr-border);
                    border-radius:8px;margin-top:4px;box-shadow:0 8px 24px rgba(0,0,0,.25);">
            <template x-for="o in available" :key="'opt-'+o.id">
                <button type="button"
                        @click="add(o.id)"
                        style="display:block;width:100%;text-align:left;padding:7px 12px;background:none;
                               border:none;color:var(--clr-text);cursor:pointer;font-size:13px;"
                        onmouseover="this.style.background='var(--clr-surface-3)'"
                        onmouseout="this.style.background='none'"
                        x-text="o.label"></button>
            </template>
        </div>
    </div>

    @if(!empty($hint))
        <p class="sbn-form-hint">{{ $hint }}</p>
    @endif
</div>
