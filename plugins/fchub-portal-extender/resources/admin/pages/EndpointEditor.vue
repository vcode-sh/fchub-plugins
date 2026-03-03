<template>
  <div>
    <div class="page-header">
      <div class="page-header-left">
        <el-button text :icon="ArrowLeft" @click="router.push('/')">Back</el-button>
        <h1 class="page-title">{{ isNew ? 'New Endpoint' : 'Edit Endpoint' }}</h1>
      </div>
    </div>

    <div v-if="loadingEndpoint" class="loading-state">
      <el-skeleton :rows="8" animated />
    </div>

    <div v-else class="editor-card">
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-position="top"
        @submit.prevent="save"
      >
        <div class="form-section">
          <h3 class="form-section-title">Basic Settings</h3>

          <el-form-item label="Title" prop="title">
            <el-input
              v-model="form.title"
              placeholder="e.g. Support Tickets"
              @input="onTitleInput"
            />
          </el-form-item>

          <el-form-item label="Slug" prop="slug">
            <el-input v-model="form.slug" placeholder="e.g. support-tickets" @input="slugManuallyEdited = true">
              <template #prepend>/</template>
            </el-input>
            <div class="field-tip">URL path in the Customer Portal. Lowercase, hyphens only.</div>
          </el-form-item>

          <el-form-item label="Status">
            <el-switch
              v-model="form.statusActive"
              active-text="Active"
              inactive-text="Inactive"
            />
          </el-form-item>
        </div>

        <div class="form-section">
          <h3 class="form-section-title">Content Source</h3>

          <el-form-item label="Type" prop="type">
            <el-radio-group v-model="form.type">
              <el-radio-button value="page_id">WordPress Page</el-radio-button>
              <el-radio-button value="shortcode">Shortcode</el-radio-button>
              <el-radio-button value="html">HTML / Custom Code</el-radio-button>
              <el-radio-button value="iframe">External URL (iframe)</el-radio-button>
              <el-radio-button value="redirect">Redirect / Link</el-radio-button>
              <el-radio-button value="custom_post">Post / CPT</el-radio-button>
            </el-radio-group>
          </el-form-item>

          <el-form-item v-if="form.type === 'page_id'" label="Page" prop="page_id">
            <el-select
              v-model="form.page_id"
              filterable
              remote
              :remote-method="searchPages"
              :loading="pagesLoading"
              placeholder="Search for a page..."
              style="width: 100%"
            >
              <el-option
                v-for="page in pageOptions"
                :key="page.id"
                :label="page.title"
                :value="page.id"
              />
            </el-select>
          </el-form-item>

          <el-form-item v-if="form.type === 'shortcode'" label="Shortcode" prop="shortcode">
            <el-input
              v-model="form.shortcode"
              placeholder='e.g. [my_shortcode id="123"]'
            />
            <div class="field-tip">The shortcode will be rendered inside the portal layout.</div>
          </el-form-item>

          <el-form-item v-if="form.type === 'html'" label="HTML Content" prop="html_content">
            <el-input
              v-model="form.html_content"
              type="textarea"
              :rows="10"
              placeholder="Enter HTML content..."
            />
            <div class="field-tip">HTML, inline CSS and shortcodes are supported. Script tags are stripped for security.</div>
          </el-form-item>

          <el-form-item v-if="form.type === 'iframe'" label="URL" prop="iframe_url">
            <el-input v-model="form.iframe_url" placeholder="https://example.com/page" />
            <div class="field-tip">The external page will be embedded in an iframe inside the portal.</div>
          </el-form-item>

          <el-form-item v-if="form.type === 'iframe'" label="Iframe Height (px)">
            <el-input-number
              v-model="form.iframe_height"
              :min="200"
              :max="2000"
              :step="50"
            />
          </el-form-item>

          <el-form-item v-if="form.type === 'redirect'" label="Redirect URL" prop="redirect_url">
            <el-input v-model="form.redirect_url" placeholder="https://example.com" />
            <div class="field-tip">Users will be redirected to this URL when they click the menu item.</div>
          </el-form-item>

          <el-form-item v-if="form.type === 'redirect'" label="Open in New Tab">
            <el-switch
              v-model="form.redirect_new_tab"
              active-text="New tab"
              inactive-text="Same tab"
            />
          </el-form-item>

          <el-form-item v-if="form.type === 'custom_post'" label="Post Type" prop="cpt_post_type">
            <el-select
              v-model="form.cpt_post_type"
              placeholder="Select a post type..."
              style="width: 100%"
              @change="onPostTypeChange"
            >
              <el-option
                v-for="pt in postTypeOptions"
                :key="pt.name"
                :label="pt.label"
                :value="pt.name"
              />
            </el-select>
          </el-form-item>

          <el-form-item v-if="form.type === 'custom_post' && form.cpt_post_type" label="Post" prop="cpt_post_id">
            <el-select
              v-model="form.cpt_post_id"
              filterable
              remote
              :remote-method="searchCptPosts"
              :loading="cptPostsLoading"
              placeholder="Search for a post..."
              style="width: 100%"
            >
              <el-option
                v-for="p in cptPostOptions"
                :key="p.id"
                :label="p.title"
                :value="p.id"
              />
            </el-select>
          </el-form-item>
        </div>

        <div v-if="form.type !== 'redirect'" class="form-section">
          <h3 class="form-section-title">Display</h3>

          <el-form-item label="Scrollable Container">
            <div>
              <el-switch
                v-model="form.scroll_enabled"
                active-text="Enabled"
                inactive-text="Disabled"
              />
              <div class="field-tip">
                Wraps content in a scrollable box. Prevents long pages from stretching the entire portal layout.
              </div>
            </div>
          </el-form-item>

          <el-form-item v-if="form.scroll_enabled" label="Height Mode">
            <el-radio-group v-model="form.scroll_mode">
              <el-radio-button value="auto">Auto-fit viewport</el-radio-button>
              <el-radio-button value="fixed">Fixed height (px)</el-radio-button>
            </el-radio-group>
          </el-form-item>

          <el-alert
            v-if="form.scroll_enabled"
            :title="form.scroll_mode === 'auto'
              ? 'Automatically fills the remaining viewport height — content scrolls within the portal frame without the page itself scrolling.'
              : 'Set a fixed pixel height. Content taller than this will show a vertical scrollbar.'"
            type="info"
            :closable="false"
            show-icon
            style="margin-bottom: 18px;"
          />

          <el-form-item v-if="form.scroll_enabled && form.scroll_mode === 'fixed'" label="Max Height (px)">
            <el-input-number
              v-model="form.scroll_height"
              :min="200"
              :max="2000"
              :step="50"
            />
          </el-form-item>
        </div>

        <div class="form-section">
          <h3 class="form-section-title">Icon</h3>

          <el-form-item label="Icon Type">
            <el-radio-group v-model="form.icon_type">
              <el-radio-button value="svg">SVG Code</el-radio-button>
              <el-radio-button value="dashicon">Dashicon</el-radio-button>
              <el-radio-button value="url">Image URL</el-radio-button>
            </el-radio-group>
          </el-form-item>

          <el-form-item v-if="form.icon_type === 'svg'" label="SVG Markup">
            <el-input
              v-model="form.icon_value"
              type="textarea"
              :rows="4"
              placeholder='<svg xmlns="http://www.w3.org/2000/svg" ...>...</svg>'
            />
            <div v-if="form.icon_value" class="icon-preview-box">
              <span class="icon-preview-label">Preview:</span>
              <span class="icon-preview-render" v-html="form.icon_value" />
            </div>
          </el-form-item>

          <el-form-item v-if="form.icon_type === 'dashicon'" label="Dashicon Class">
            <el-select
              v-model="form.icon_value"
              filterable
              placeholder="Select a dashicon..."
              style="width: 100%"
            >
              <el-option
                v-for="icon in dashicons"
                :key="icon.value"
                :label="icon.label"
                :value="icon.value"
              >
                <div style="display: flex; align-items: center; gap: 8px;">
                  <span :class="'dashicons ' + icon.value" style="font-size:16px;width:16px;height:16px;" />
                  <span>{{ icon.label }}</span>
                </div>
              </el-option>
            </el-select>
            <div v-if="form.icon_value && form.icon_type === 'dashicon'" class="icon-preview-box">
              <span class="icon-preview-label">Preview:</span>
              <span :class="'dashicons ' + form.icon_value" style="font-size:20px;width:20px;height:20px;" />
            </div>
          </el-form-item>

          <el-form-item v-if="form.icon_type === 'url'" label="Image URL">
            <el-input v-model="form.icon_value" placeholder="https://example.com/icon.png" />
            <div v-if="form.icon_value && form.icon_type === 'url'" class="icon-preview-box">
              <span class="icon-preview-label">Preview:</span>
              <img :src="form.icon_value" style="width:20px;height:20px;object-fit:contain;" alt="" />
            </div>
          </el-form-item>
        </div>

        <div class="form-footer">
          <el-button @click="router.push('/')">Cancel</el-button>
          <el-button type="primary" :loading="saving" @click="save">
            {{ isNew ? 'Create Endpoint' : 'Save Changes' }}
          </el-button>
        </div>
      </el-form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { ArrowLeft } from '@element-plus/icons-vue'
import { endpoints as endpointsApi, pages as pagesApi, postTypes as postTypesApi, posts as postsApi } from '@/api/index.js'

const props = defineProps({
  isNew: { type: Boolean, default: false },
})

const route = useRoute()
const router = useRouter()
const formRef = ref(null)
const saving = ref(false)
const loadingEndpoint = ref(false)
const pagesLoading = ref(false)
const pageOptions = ref([])
const postTypeOptions = ref([])
const cptPostOptions = ref([])
const cptPostsLoading = ref(false)
const slugManuallyEdited = ref(false)

const form = reactive({
  title: '',
  slug: '',
  type: 'page_id',
  page_id: null,
  shortcode: '',
  html_content: '',
  iframe_url: '',
  iframe_height: 600,
  redirect_url: '',
  redirect_new_tab: false,
  cpt_post_type: '',
  cpt_post_id: null,
  icon_type: 'svg',
  icon_value: '',
  statusActive: true,
  scroll_enabled: false,
  scroll_mode: 'auto',
  scroll_height: 600,
})

const rules = {
  title: [{ required: true, message: 'Title is required', trigger: 'blur' }],
  slug: [
    { required: true, message: 'Slug is required', trigger: 'blur' },
    { pattern: /^[a-z0-9]+(?:-[a-z0-9]+)*$/, message: 'Lowercase letters, numbers, and hyphens only', trigger: 'blur' },
  ],
  page_id: [{
    validator: (rule, value, callback) => {
      if (form.type === 'page_id' && !value) {
        callback(new Error('Please select a page'))
      } else {
        callback()
      }
    },
    trigger: 'change',
  }],
  shortcode: [{
    validator: (rule, value, callback) => {
      if (form.type === 'shortcode' && !value) {
        callback(new Error('Please enter a shortcode'))
      } else {
        callback()
      }
    },
    trigger: 'blur',
  }],
  html_content: [{
    validator: (rule, value, callback) => {
      if (form.type === 'html' && !value) {
        callback(new Error('Please enter HTML content'))
      } else {
        callback()
      }
    },
    trigger: 'blur',
  }],
  iframe_url: [{
    validator: (rule, value, callback) => {
      if (form.type === 'iframe' && !value) {
        callback(new Error('Please enter a URL'))
      } else {
        callback()
      }
    },
    trigger: 'blur',
  }],
  redirect_url: [{
    validator: (rule, value, callback) => {
      if (form.type === 'redirect' && !value) {
        callback(new Error('Please enter a redirect URL'))
      } else {
        callback()
      }
    },
    trigger: 'blur',
  }],
  cpt_post_type: [{
    validator: (rule, value, callback) => {
      if (form.type === 'custom_post' && !value) {
        callback(new Error('Please select a post type'))
      } else {
        callback()
      }
    },
    trigger: 'change',
  }],
  cpt_post_id: [{
    validator: (rule, value, callback) => {
      if (form.type === 'custom_post' && !value) {
        callback(new Error('Please select a post'))
      } else {
        callback()
      }
    },
    trigger: 'change',
  }],
}

const dashicons = [
  { value: 'dashicons-admin-home', label: 'Home' },
  { value: 'dashicons-admin-users', label: 'Users' },
  { value: 'dashicons-admin-post', label: 'Post' },
  { value: 'dashicons-admin-page', label: 'Page' },
  { value: 'dashicons-admin-comments', label: 'Comments' },
  { value: 'dashicons-admin-tools', label: 'Tools' },
  { value: 'dashicons-admin-settings', label: 'Settings' },
  { value: 'dashicons-admin-generic', label: 'Generic' },
  { value: 'dashicons-star-filled', label: 'Star' },
  { value: 'dashicons-heart', label: 'Heart' },
  { value: 'dashicons-book', label: 'Book' },
  { value: 'dashicons-calendar-alt', label: 'Calendar' },
  { value: 'dashicons-cart', label: 'Cart' },
  { value: 'dashicons-email', label: 'Email' },
  { value: 'dashicons-tickets-alt', label: 'Tickets' },
  { value: 'dashicons-groups', label: 'Groups' },
  { value: 'dashicons-portfolio', label: 'Portfolio' },
  { value: 'dashicons-shield', label: 'Shield' },
  { value: 'dashicons-awards', label: 'Awards' },
  { value: 'dashicons-megaphone', label: 'Megaphone' },
  { value: 'dashicons-lightbulb', label: 'Lightbulb' },
  { value: 'dashicons-share', label: 'Share' },
  { value: 'dashicons-chart-bar', label: 'Chart Bar' },
  { value: 'dashicons-format-video', label: 'Video' },
  { value: 'dashicons-format-audio', label: 'Audio' },
  { value: 'dashicons-format-gallery', label: 'Gallery' },
  { value: 'dashicons-location', label: 'Location' },
  { value: 'dashicons-phone', label: 'Phone' },
  { value: 'dashicons-info', label: 'Info' },
  { value: 'dashicons-yes-alt', label: 'Checkmark' },
]

function onTitleInput() {
  if (!slugManuallyEdited.value) {
    form.slug = form.title
      .toLowerCase()
      .replace(/[^a-z0-9\s-]/g, '')
      .replace(/\s+/g, '-')
      .replace(/-+/g, '-')
      .replace(/^-|-$/g, '')
  }
}

async function fetchPostTypes() {
  try {
    const res = await postTypesApi.list()
    postTypeOptions.value = res.post_types || []
  } catch {
    postTypeOptions.value = []
  }
}

function onPostTypeChange() {
  form.cpt_post_id = null
  cptPostOptions.value = []
  if (form.cpt_post_type) {
    searchCptPosts('')
  }
}

async function searchCptPosts(query) {
  if (!form.cpt_post_type) return
  cptPostsLoading.value = true
  try {
    const res = await postsApi.search(form.cpt_post_type, query || '')
    cptPostOptions.value = res.posts || []
  } catch {
    cptPostOptions.value = []
  } finally {
    cptPostsLoading.value = false
  }
}

async function searchPages(query) {
  pagesLoading.value = true
  try {
    const res = await pagesApi.search(query || '')
    pageOptions.value = res.pages || []
  } catch {
    pageOptions.value = []
  } finally {
    pagesLoading.value = false
  }
}

async function loadEndpoint(id) {
  loadingEndpoint.value = true
  try {
    const res = await endpointsApi.list()
    const ep = (res.endpoints || []).find(e => e.id === id)
    if (!ep) {
      ElMessage.error('Endpoint not found')
      router.push('/')
      return
    }

    form.title = ep.title
    form.slug = ep.slug
    form.type = ep.type
    form.page_id = ep.page_id || null
    form.shortcode = ep.shortcode || ''
    form.icon_type = ep.icon_type || 'svg'
    form.icon_value = ep.icon_value || ''
    form.statusActive = ep.status === 'active'
    form.html_content = ep.html_content || ''
    form.iframe_url = ep.iframe_url || ''
    form.iframe_height = ep.iframe_height || 600
    form.redirect_url = ep.redirect_url || ''
    form.redirect_new_tab = !!ep.redirect_new_tab
    form.cpt_post_type = ep.cpt_post_type || ''
    form.cpt_post_id = ep.cpt_post_id || null
    form.scroll_enabled = !!ep.scroll_enabled
    form.scroll_mode = ep.scroll_mode || 'auto'
    form.scroll_height = ep.scroll_height || 600
    slugManuallyEdited.value = true

    // Pre-load the selected page into the dropdown
    if (ep.type === 'page_id' && ep.page_id) {
      const pagesRes = await pagesApi.search('')
      pageOptions.value = pagesRes.pages || []
      // If the current page isn't in the initial list, add it
      if (!pageOptions.value.find(p => p.id === ep.page_id)) {
        pageOptions.value.unshift({ id: ep.page_id, title: `Page #${ep.page_id}` })
      }
    }

    // Pre-load CPT post options when editing a custom_post endpoint
    if (ep.type === 'custom_post' && ep.cpt_post_type) {
      if (ep.cpt_post_id) {
        const postsRes = await postsApi.search(ep.cpt_post_type, '')
        cptPostOptions.value = postsRes.posts || []
        if (!cptPostOptions.value.find(p => p.id === ep.cpt_post_id)) {
          cptPostOptions.value.unshift({ id: ep.cpt_post_id, title: `Post #${ep.cpt_post_id}` })
        }
      }
    }
  } catch (e) {
    ElMessage.error(e.message || 'Failed to load endpoint')
  } finally {
    loadingEndpoint.value = false
  }
}

async function save() {
  if (!formRef.value) return

  try {
    await formRef.value.validate()
  } catch {
    return
  }

  saving.value = true

  const payload = {
    title: form.title,
    slug: form.slug,
    type: form.type,
    page_id: form.type === 'page_id' ? form.page_id : 0,
    shortcode: form.type === 'shortcode' ? form.shortcode : '',
    html_content: form.type === 'html' ? form.html_content : '',
    iframe_url: form.type === 'iframe' ? form.iframe_url : '',
    iframe_height: form.type === 'iframe' ? form.iframe_height : 600,
    redirect_url: form.type === 'redirect' ? form.redirect_url : '',
    redirect_new_tab: form.type === 'redirect' ? form.redirect_new_tab : false,
    cpt_post_type: form.type === 'custom_post' ? form.cpt_post_type : '',
    cpt_post_id: form.type === 'custom_post' ? form.cpt_post_id : 0,
    icon_type: form.icon_type,
    icon_value: form.icon_value,
    status: form.statusActive ? 'active' : 'inactive',
    scroll_enabled: form.type !== 'redirect' ? form.scroll_enabled : false,
    scroll_mode: form.scroll_mode,
    scroll_height: form.scroll_height,
  }

  try {
    if (props.isNew) {
      await endpointsApi.create(payload)
      ElMessage.success('Endpoint created')
    } else {
      await endpointsApi.update(route.params.id, payload)
      ElMessage.success('Endpoint updated')
    }
    router.push('/')
  } catch (e) {
    ElMessage.error(e.message || 'Failed to save endpoint')
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchPostTypes()
  if (!props.isNew && route.params.id) {
    loadEndpoint(route.params.id)
  } else {
    searchPages('')
  }
})
</script>

<style scoped>
.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 24px;
}

.page-header-left {
  display: flex;
  align-items: center;
  gap: 8px;
}

.page-title {
  font-size: 20px;
  font-weight: 700;
  color: var(--fchub-text-primary);
  margin: 0;
}

.editor-card {
  background: var(--fchub-card-bg);
  border-radius: var(--fchub-radius-card);
  overflow: hidden;
}

.form-section {
  padding: 24px 32px 8px;
}

.form-section-title {
  font-size: 15px;
  font-weight: 700;
  color: var(--fchub-text-primary);
  margin: 0 0 20px 0;
  padding-bottom: 12px;
  border-bottom: 1px solid var(--fchub-border-color);
}

.form-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding: 20px 32px;
  border-top: 1px solid var(--fchub-border-color);
}

.field-tip {
  font-size: 12px;
  color: var(--fchub-text-secondary);
  margin-top: 4px;
}

.icon-preview-box {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 8px;
  padding: 8px 12px;
  background: var(--fchub-page-bg);
  border-radius: 6px;
}

body.dark .icon-preview-box {
  background: #1a1d23;
}

.icon-preview-label {
  font-size: 12px;
  color: var(--fchub-text-secondary);
}

.icon-preview-render {
  display: inline-flex;
  align-items: center;
  color: var(--fchub-text-primary);
}

.icon-preview-render :deep(svg) {
  width: 20px;
  height: 20px;
}

.loading-state {
  background: var(--fchub-card-bg);
  border-radius: var(--fchub-radius-card);
  padding: 24px;
}
</style>
