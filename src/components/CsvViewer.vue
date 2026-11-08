<template>
	<div class="csv-viewer">
		<!-- Window too small to hold a whole row: show it raw -->
		<pre v-if="!table.header && table.rows.length === 0">{{ before + chunk + after }}</pre>
		<table v-else>
			<thead v-if="table.header">
				<tr>
					<th v-for="(cell, i) in table.header" :key="i">
						{{ cell }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(row, r) in table.rows" :key="r" :class="{ 'csv-viewer__chunk': row.inChunk }">
					<td v-for="(cell, i) in row.cells" :key="i">
						{{ cell }}
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script setup>
import { computed } from 'vue'
import { parseCsvWindow } from '../utils/csvTable.js'

const props = defineProps({
	before: { type: String, default: '' },
	chunk: { type: String, default: '' },
	after: { type: String, default: '' },
	hasMoreBefore: { type: Boolean, default: false },
	hasMoreAfter: { type: Boolean, default: false },
})

const table = computed(() => parseCsvWindow({
	before: props.before,
	chunk: props.chunk,
	after: props.after,
	hasMoreBefore: props.hasMoreBefore,
	hasMoreAfter: props.hasMoreAfter,
}))
</script>

<style scoped lang="scss">
.csv-viewer {
	// Own scroll box so the sticky header sticks while rows scroll.
	overflow: auto;
	max-height: 70vh;
	font-size: 13px;

	table {
		border-collapse: collapse;
		width: max-content;
		min-width: 100%;
	}

	th, td {
		padding: 6px 10px;
		border: 1px solid var(--color-border);
		text-align: start;
		vertical-align: top;
		white-space: pre-wrap;
		max-width: 40ch;
		// Break unbroken tokens (URLs, IDs) too, or they spill over the next column.
		overflow-wrap: anywhere;
	}

	th {
		background: var(--color-background-dark);
		font-weight: 600;
		position: sticky;
		top: 0;
	}

	&__chunk td {
		background: var(--color-primary-element-light);
	}
}
</style>
