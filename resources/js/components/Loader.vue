<template>
    <transition name="fade">
    <div class="absolute h-full w-full left-0 right-0 mx-auto top-0 flex items-center justify-center z-10">
        <div class="px-4 py-2 bg-gray-600 shadow-lg text-white flex items-center">
            <svg class="animate-spin" xmlns="http://www.w3.org/2000/svg" version="1.1" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:svgjs="http://svgjs.com/svgjs" viewBox="0 0 24 24" width="24" height="24"><g transform="matrix(1,0,0,1,0,0)"><path d="M16.5,22.038A11,11,0,1,1,12,1" fill="none" stroke="#ffffff" stroke-linecap="round" stroke-linejoin="round"></path><path d="M18.684,3.262c.246.187.483.386.712.593s.449.426.659.652" fill="none" stroke="#ffffff" stroke-linecap="round" stroke-linejoin="round"></path><path d="M21.75,6.905q.215.411.394.839t.322.869" fill="none" stroke="#ffffff" stroke-linecap="round" stroke-linejoin="round"></path><path d="M22.989,11.5q.021.463,0,.927c-.013.308-.038.617-.076.923" fill="none" stroke="#ffffff" stroke-linecap="round" stroke-linejoin="round"></path><path d="M22.169,16.193q-.177.429-.39.841t-.458.806" fill="none" stroke="#ffffff" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.286,1.5q.481.15.943.343" fill="none" stroke="#ffffff" stroke-linecap="round" stroke-linejoin="round"></path><path d="M19.725,19.829c-.237.234-.485.458-.743.669" fill="none" stroke="#ffffff" stroke-linecap="round" stroke-linejoin="round"></path><path d="M12,4a8,8,0,1,1-8,8" fill="none" stroke="#ffffff" stroke-linecap="round" stroke-linejoin="round"></path></g></svg>
            <span class="ml-1" v-html="text"></span>
        </div>
    </div>
    </transition>
</template>

<script>
export default {
    props: {
        text: {
            type: Object,
            default: 'Loading...'
        }
    }
}
</script>
