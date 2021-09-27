<template>
    <div class="card p-2">
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
                events: this.calendarJsonUrl,
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
            }
        }
    },

    components: {
        FullCalendar
    }, 
  
}
</script>
