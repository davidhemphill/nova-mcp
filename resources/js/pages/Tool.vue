<template>
  <div>
    <Head title="Nova MCP" />

    <Heading class="mb-6">Nova MCP</Heading>

    <p class="mb-6 text-gray-500 dark:text-gray-400">
      Everything registered with Nova &mdash; resources, lenses, actions,
      dashboards and notifications &mdash; is published to AI clients over the
      Model Context Protocol. Every call runs as the Nova user who made it and
      is subject to that user&rsquo;s policies.
    </p>

    <LoadingView :loading="loading">
      <Card v-if="error" class="mb-6 p-6">
        <p class="text-red-500">{{ error }}</p>
      </Card>

      <template v-else-if="catalog">
        <Card class="mb-6 p-6">
          <h2 class="mb-4 text-lg font-bold">Connecting</h2>

          <div class="mb-6">
            <h3 class="mb-1 text-sm font-bold">HTTP</h3>
            <p v-if="!catalog.transports.web.enabled" class="text-gray-500">
              Disabled. Set <code>NOVA_MCP_WEB=true</code> to enable it.
            </p>
            <template v-else>
              <pre class="overflow-x-auto rounded bg-gray-100 p-3 text-sm dark:bg-gray-900">{{ catalog.transports.web.url }}</pre>
              <p class="mt-2 text-gray-500">
                Send your token as
                <code>Authorization: Bearer &lt;token&gt;</code>.
              </p>
            </template>
          </div>

          <div>
            <h3 class="mb-1 text-sm font-bold">Local (stdio)</h3>
            <p v-if="!catalog.transports.local.enabled" class="text-gray-500">
              Disabled. Set <code>NOVA_MCP_LOCAL=true</code> to enable it.
            </p>
            <template v-else>
              <pre class="overflow-x-auto rounded bg-gray-100 p-3 text-sm dark:bg-gray-900">php artisan mcp:start {{ catalog.transports.local.handle }}</pre>
              <p class="mt-2 text-gray-500">
                Put your token in the MCP client&rsquo;s
                <code>NOVA_MCP_TOKEN</code> environment variable.
              </p>
            </template>
          </div>
        </Card>

        <Card class="mb-6 p-6">
          <h2 class="mb-1 text-lg font-bold">Your MCP tokens</h2>
          <p class="mb-4 text-sm text-gray-500">
            Tokens act as you, so they can never reach anything you cannot
            reach in Nova. Shown once &mdash; revoke one to cut off the agent
            holding it.
          </p>

          <div
            v-if="issuedToken"
            class="mb-4 rounded border border-green-200 bg-green-50 p-3 dark:border-green-800 dark:bg-green-900"
          >
            <p class="mb-2 text-sm font-bold">
              Copy this now &mdash; it will not be shown again.
            </p>
            <pre class="overflow-x-auto text-xs">{{ issuedToken }}</pre>
          </div>

          <div class="mb-4 flex gap-2">
            <input
              v-model="tokenName"
              type="text"
              placeholder="Token name, e.g. Claude Desktop"
              class="form-control form-input form-control-bordered flex-1"
              @keyup.enter="createToken"
            />
            <DefaultButton :disabled="creating || !tokenName" @click="createToken">
              Create
            </DefaultButton>
          </div>

          <p v-if="tokens.length === 0" class="text-sm text-gray-500">
            No tokens yet.
          </p>

          <div
            v-for="token in tokens"
            :key="token.id"
            class="flex items-center justify-between border-b border-gray-100 py-2 last:border-0 dark:border-gray-700"
          >
            <div>
              <strong>{{ token.name }}</strong>
              <span class="ml-2 text-sm text-gray-500">
                created {{ token.createdAt }}
                <template v-if="token.lastUsedAt">
                  &middot; last used {{ token.lastUsedAt }}
                </template>
                <template v-else>&middot; never used</template>
              </span>
            </div>
            <button
              class="text-sm text-red-500 hover:underline"
              @click="revokeToken(token)"
            >
              Revoke
            </button>
          </div>
        </Card>

        <Card class="mb-6 p-6">
          <h2 class="mb-4 text-lg font-bold">Capabilities</h2>
          <ul class="space-y-1 text-gray-500">
            <li>
              Writes (create, update, delete):
              <strong>{{ catalog.capabilities.writes ? 'enabled' : 'disabled' }}</strong>
            </li>
            <li>
              Nova actions:
              <strong>{{ catalog.capabilities.actions ? 'enabled' : 'disabled' }}</strong>
            </li>
            <li>
              Always available:
              <code v-for="name in catalog.alwaysAvailable" :key="name" class="mr-2">{{ name }}</code>
            </li>
          </ul>
        </Card>

        <Card v-for="group in groups" :key="group.key" class="mb-6 p-6">
          <h2 class="mb-1 text-lg font-bold">{{ group.label }}</h2>
          <p class="mb-4 text-sm text-gray-500">{{ group.tools.length }} tools</p>

          <div
            v-for="tool in group.tools"
            :key="tool.name"
            class="border-b border-gray-100 py-3 last:border-0 dark:border-gray-700"
          >
            <div class="flex items-center gap-2">
              <code class="font-bold">{{ tool.name }}</code>
              <span
                v-if="tool.annotations.destructiveHint"
                class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-600 dark:bg-red-900 dark:text-red-200"
              >destructive</span>
              <span
                v-else-if="tool.annotations.readOnlyHint"
                class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-900 dark:text-gray-300"
              >read only</span>
            </div>
            <p class="mt-1 text-sm text-gray-500">{{ tool.description }}</p>
          </div>
        </Card>
      </template>
    </LoadingView>
  </div>
</template>

<script>
export default {
  data() {
    return {
      loading: true,
      error: null,
      catalog: null,
      tokens: [],
      tokenName: '',
      issuedToken: null,
      creating: false,
    }
  },

  computed: {
    groups() {
      if (!this.catalog) {
        return []
      }

      const labels = {
        resource: 'Resource',
        dashboard: 'Dashboard',
        notifications: 'Notifications',
      }

      const groups = new Map()

      this.catalog.tools.forEach(tool => {
        const subject = tool.subject || { type: 'other', key: 'other' }
        const key = `${subject.type}:${subject.key}`

        if (!groups.has(key)) {
          groups.set(key, {
            key,
            label: `${labels[subject.type] || subject.type}: ${subject.key}`,
            tools: [],
          })
        }

        groups.get(key).tools.push(tool)
      })

      return Array.from(groups.values())
    },
  },

  mounted() {
    Nova.request()
      .get('/nova-vendor/nova-mcp/catalog')
      .then(({ data }) => (this.catalog = data))
      .catch(() => (this.error = 'The MCP catalog could not be loaded.'))
      .finally(() => (this.loading = false))

    this.loadTokens()
  },

  methods: {
    loadTokens() {
      Nova.request()
        .get('/nova-vendor/nova-mcp/tokens')
        .then(({ data }) => (this.tokens = data.tokens))
    },

    createToken() {
      if (this.creating || !this.tokenName) {
        return
      }

      this.creating = true

      Nova.request()
        .post('/nova-vendor/nova-mcp/tokens', { name: this.tokenName })
        .then(({ data }) => {
          this.issuedToken = data.token
          this.tokenName = ''
          this.loadTokens()
        })
        .catch(() => Nova.error('The token could not be created.'))
        .finally(() => (this.creating = false))
    },

    revokeToken(token) {
      Nova.request()
        .delete(`/nova-vendor/nova-mcp/tokens/${token.id}`)
        .then(() => {
          this.tokens = this.tokens.filter(candidate => candidate.id !== token.id)
          Nova.success(`Revoked ${token.name}.`)
        })
        .catch(() => Nova.error('The token could not be revoked.'))
    },
  },
}
</script>
