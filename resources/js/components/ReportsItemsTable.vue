<template>
    <div>
        <data-list       
            :columns="columns[tableColumns]"
            :rows="items"
            :sort="true"
            :sort-column="sortColumn"
            :sort-direction="sortDirection"
        >
            <data-list-table
                :allow-column-picker="false"
            >  
                <template slot="cell-total_revenue" slot-scope="{ row: item }">
                    {{ currency }} {{ item.total_revenue }}
                </template>
                <template slot="cell-percentage" slot-scope="{ row: item }">
                    {{ Math.round(item.percentage * 100) }}%
                </template>
            </data-list-table>
        </data-list>
    </div>
</template>
<script>

export default ({
    
    props: {
        items: '',
        tableColumns: '',
        currency: ''
    },

    data() {
        return {           
            columns: { 
                'items': [
                    {
                        field: 'title',
                        fieldtype: 'text',
                        label: 'Title',
                        sortable: true
                    },
                    {
                        field: 'reservations',
                        fieldtype: 'number',
                        label: 'Reservations',
                        sortable: true
                    },
                    {
                        field: 'total_revenue',
                        fieldtype: 'number',
                        label: 'Revenue',
                        sortable: true
                    },
                    {
                        field: 'percentage',
                        fieldtype: 'number',
                        label: 'Percentage',
                        sortable: true
                    },
                ],
                'other': [
                    {
                        field: 'title',
                        fieldtype: 'text',
                        label: 'Title',
                        sortable: true
                    },
                    {
                        field: 'reservations',
                        fieldtype: 'number',
                        label: 'Reservations',
                        sortable: true
                    },
                    {
                        field: 'percentage',
                        fieldtype: 'number',
                        label: 'Percentage',
                        sortable: true
                    }
                ]
            },
            sortColumn: 'reservations',
            sortDirection: 'desc'
        }
    },

    mixins: [Listing],


})
</script>
