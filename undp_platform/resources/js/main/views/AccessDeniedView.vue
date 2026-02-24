<template>
    <main class="auth-page">
        <section class="auth-card">
            <h1>Access Denied</h1>
            <p>You do not have permission to view this page.</p>
            <router-link class="btn btn--primary" :to="{ name: 'home' }">Back to Home</router-link>
        </section>
    </main>
</template>
