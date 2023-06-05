<template>
    <div class="card p-4">
        <div class="w-full flex">
            <div class="pt-2 pb-3 flex items-center">
                <div class="mr-2">{{ __('Only show start dates') }}</div>
                <toggle-input v-model="onlyStart"></toggle-input>
            </div> 
        </div>
        <FullCalendar ref="fullCalendar" :options="calendarOptions" />
    </div>    
</template>

<script>
import FullCalendar from '@fullcalendar/vue'
import dayGridPlugin from '@fullcalendar/daygrid'
import interactionPlugin from '@fullcalendar/interaction'

export default {

    props: {
        calendarJsonUrl: String,
    },

    data() {
        return {
            calendarOptions: {
                plugins: [ dayGridPlugin, interactionPlugin ],
                initialView: 'dayGridMonth',
                navLinks: true,
                eventColor: '#2c3e50',
                events: {
                    url: this.calendarJsonUrl,
                    extraParams: () => {
                        if (this.onlyStart) {
                            return {
                                onlyStart: 1
                            }
                        }
                    },
                   
                },                
                timeZone: 'UTC',
                eventTimeFormat: { 
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false
                },
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,dayGridDay'
                },
            },
            onlyStart: false
        }
    },

    watch: {
        onlyStart() {
            let calApi = this.$refs.fullCalendar.getApi()
            calApi.refetchEvents()
        }
    },

    components: {
        FullCalendar
    }, 
  
}
</script>
