<template>
  <div class="fchub-ty-wrap">
    <div class="fchub-ty-row">
      <span class="fchub-ty-label">Enable custom redirect</span>
      <el-switch :model-value="enabled" :disabled="!loaded" @change="onToggle" />
    </div>

    <transition name="el-fade-in-linear">
      <div v-if="enabled && loaded" class="fchub-ty-details">
        <div class="fchub-ty-summary">
          <span class="fchub-ty-summary-text">{{ summaryText }}</span>
          <a
            v-if="previewUrl"
            :href="previewUrl"
            target="_blank"
            rel="noopener"
            class="fchub-ty-preview"
          >
            Preview
          </a>
        </div>
        <el-button size="small" @click="dialogVisible = true">Configure</el-button>
      </div>
    </transition>

    <!-- Config dialog -->
    <el-dialog
      v-model="dialogVisible"
      title="Configure Thank You Redirect"
      width="680px"
      :close-on-click-modal="false"
      append-to-body
    >
      <div class="fchub-ty-dialog-body">
        <div class="fchub-ty-field">
          <label class="fchub-ty-field-label">Redirect type</label>
          <el-radio-group v-model="form.type">
            <el-radio-button value="page">Page</el-radio-button>
            <el-radio-button value="post">Post</el-radio-button>
            <el-radio-button value="cpt">Custom Post Type</el-radio-button>
            <el-radio-button value="url">Custom URL</el-radio-button>
          </el-radio-group>
        </div>

        <div v-if="form.type === 'cpt'" class="fchub-ty-field">
          <label class="fchub-ty-field-label">Post type</label>
          <el-select
            v-model="form.post_type"
            placeholder="Select post type"
            :loading="postTypesLoading"
            style="width: 100%"
          >
            <el-option
              v-for="pt in availablePostTypes"
              :key="pt.slug"
              :label="pt.label"
              :value="pt.slug"
            />
          </el-select>
        </div>

        <div v-if="needsContentPicker && effectivePostType" class="fchub-ty-field">
          <label class="fchub-ty-field-label">Select content</label>
          <el-select
            v-model="form.target_id"
            filterable
            remote
            :remote-method="remoteSearch"
            placeholder="Search by title..."
            :loading="searchLoading"
            clearable
            style="width: 100%"
            @focus="onFocusSearch"
          >
            <el-option
              v-for="item in searchResults"
              :key="item.id"
              :label="item.title"
              :value="item.id"
            >
              <span>{{ item.title }}</span>
              <span style="float: right; color: #999; font-size: 12px">
                ID: {{ item.id }}
              </span>
            </el-option>
          </el-select>
          <div v-if="selectedPermalink" class="fchub-ty-preview-link">
            <a :href="selectedPermalink" target="_blank" rel="noopener">
              Preview target &rarr;
            </a>
          </div>
        </div>

        <div v-if="form.type === 'url'" class="fchub-ty-field">
          <label class="fchub-ty-field-label">Redirect URL</label>
          <el-input v-model="form.url" placeholder="https://example.com/thank-you" clearable />
        </div>
      </div>

      <template #footer>
        <el-button @click="dialogVisible = false">Cancel</el-button>
        <el-button type="primary" :loading="saving" @click="onDialogSave">Save</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue";
import {
	fetchSettings,
	postTypes as fetchPostTypes,
	saveSettings,
	search,
} from "../api";
import type {
	PostType,
	RedirectSettings,
	RedirectType,
	SearchResult,
	WidgetData,
} from "../types";

const TYPE_LABELS: Record<RedirectType, string> = {
	page: "Page",
	post: "Post",
	cpt: "Custom Post Type",
	url: "Custom URL",
};

const props = defineProps<{ data: WidgetData }>();

const enabled = ref(false);
const settings = reactive<RedirectSettings>({
	enabled: false,
	type: "url",
	target_id: null,
	url: "",
	post_type: "",
	target_label: "",
	target_permalink: "",
});
const loaded = ref(false);
const saving = ref(false);
const dialogVisible = ref(false);

// Dialog form state
const form = reactive({
	type: "url" as RedirectType,
	target_id: null as number | null,
	url: "",
	post_type: "",
});

const searchResults = ref<SearchResult[]>([]);
const searchLoading = ref(false);
const availablePostTypes = ref<PostType[]>([]);
const postTypesLoading = ref(false);
let searchTimer: ReturnType<typeof setTimeout> | null = null;

const productId = computed(() => props.data.editableProduct?.ID);

const needsContentPicker = computed(
	() => form.type === "page" || form.type === "post" || form.type === "cpt",
);

const effectivePostType = computed(() => {
	if (form.type === "page") return "page";
	if (form.type === "post") return "post";
	if (form.type === "cpt") return form.post_type;
	return "";
});

const summaryText = computed(() => {
	if (!enabled.value) return "";
	if (settings.type === "url" && settings.url) return `URL: ${settings.url}`;
	if (
		(settings.type === "page" ||
			settings.type === "post" ||
			settings.type === "cpt") &&
		settings.target_label
	) {
		return `${TYPE_LABELS[settings.type]}: ${settings.target_label}`;
	}
	return "Not configured";
});

const previewUrl = computed(() => {
	if (settings.type === "url" && settings.url) return settings.url;
	return settings.target_permalink || "";
});

const selectedPermalink = computed(() => {
	if (!form.target_id) return "";
	const match = searchResults.value.find((r) => r.id === form.target_id);
	return match?.permalink || settings.target_permalink || "";
});

// Sync dialog form from settings when opened
watch(dialogVisible, (val) => {
	if (val) {
		form.type = settings.type;
		form.target_id = settings.target_id;
		form.url = settings.url;
		form.post_type = settings.post_type;

		if (form.target_id && settings.target_label) {
			searchResults.value = [
				{
					id: settings.target_id,
					title: settings.target_label,
					permalink: settings.target_permalink || "",
					post_type: effectivePostType.value,
				},
			];
		}

		if (availablePostTypes.value.length === 0) {
			loadPostTypes();
		}
	}
});

// Reset content picker when type or post_type changes
watch(
	() => form.type,
	() => {
		searchResults.value = [];
		form.target_id = null;
	},
);
watch(
	() => form.post_type,
	() => {
		searchResults.value = [];
		form.target_id = null;
	},
);

onMounted(async () => {
	if (!productId.value) return;
	try {
		const data = await fetchSettings(productId.value);
		enabled.value = data.enabled;
		Object.assign(settings, data);
	} catch {
		// fall back to defaults
	} finally {
		loaded.value = true;
	}
});

async function loadPostTypes(): Promise<void> {
	postTypesLoading.value = true;
	try {
		availablePostTypes.value = await fetchPostTypes();
	} catch {
		// ignore
	} finally {
		postTypesLoading.value = false;
	}
}

function remoteSearch(query: string): void {
	const pt = effectivePostType.value;
	if (!pt) return;

	if (searchTimer) clearTimeout(searchTimer);
	searchTimer = setTimeout(async () => {
		searchLoading.value = true;
		try {
			searchResults.value = await search(pt, query);
		} catch {
			searchResults.value = [];
		} finally {
			searchLoading.value = false;
		}
	}, 300);
}

function onFocusSearch(): void {
	if (searchResults.value.length === 0 && effectivePostType.value) {
		remoteSearch("");
	}
}

async function onToggle(val: boolean): Promise<void> {
	if (!productId.value) return;
	enabled.value = val;
	saving.value = true;
	try {
		await saveSettings(productId.value, {
			enabled: val,
			type: settings.type,
			target_id: settings.target_id,
			url: settings.url,
			post_type: settings.post_type,
		});
		window.ElMessage?.({ type: "success", message: "Saved." });
	} catch (err) {
		const message =
			err instanceof Error ? err.message : "Could not save.";
		window.ElMessage?.({ type: "error", message });
	} finally {
		saving.value = false;
	}
}

async function onDialogSave(): Promise<void> {
	if (!productId.value) return;
	saving.value = true;
	try {
		const data = await saveSettings(productId.value, {
			enabled: enabled.value,
			type: form.type,
			target_id: needsContentPicker.value ? form.target_id : null,
			url: form.type === "url" ? form.url : "",
			post_type: form.type === "cpt" ? form.post_type : "",
		});
		Object.assign(settings, data);
		dialogVisible.value = false;
		window.ElMessage?.({ type: "success", message: "Saved." });
	} catch (err) {
		const message =
			err instanceof Error ? err.message : "Could not save.";
		window.ElMessage?.({ type: "error", message });
	} finally {
		saving.value = false;
	}
}
</script>
