<template>
  <div class="app-logo">
    <img :src="logoSrc" width="100%" height="100%" alt="Logo" />
  </div>
</template>

<script setup lang="ts">
import logoSrc from '@/assets/svg-icon/btdc-logo.png';
</script>

<style scoped>
.app-logo {
  display: flex;
  align-items: center;
  justify-content: center;
}
</style>
