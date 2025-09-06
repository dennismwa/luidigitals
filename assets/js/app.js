// Luidigitals Wallet System - Main JavaScript File

class WalletApp {
    constructor() {
        this.isDarkMode = document.documentElement.classList.contains('dark');
        this.notificationQueue = [];
        this.init();
    }

    init() {
        this.initServiceWorker();
        this.initEventListeners();
        this.initOfflineHandler();
        this.initAutoSave();
        this.checkNotifications();
    }

    // Service Worker Registration
    initServiceWorker() {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js')
                .then(registration => {
                    console.log('SW registered:', registration);
                    this.checkForUpdates(registration);
                })
                .catch(error => {
                    console.log('SW registration failed:', error);
                });
        }
    }

    // Check for app updates
    checkForUpdates(registration) {
        registration.addEventListener('updatefound', () => {
            const newWorker = registration.installing;
            newWorker.addEventListener('statechange', () => {
                if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                    this.showUpdateNotification();
                }
            });
        });
    }

    // Show update notification
    showUpdateNotification() {
        const notification = this.createNotification(
            'App Update Available',
            'A new version is available. Click to refresh.',
            'info',
            () => window.location.reload()
        );
        this.showNotification(notification);
    }

    // Event Listeners
    initEventListeners() {
        // Global keyboard shortcuts
        document.addEventListener('keydown', this.handleKeyboardShortcuts.bind(this));
        
        // Online/Offline status
        window.addEventListener('online', this.handleOnline.bind(this));
        window.addEventListener('offline', this.handleOffline.bind(this));
        
        // Form auto-save
        document.addEventListener('input', this.handleAutoSave.bind(this));
        
        // Click outside to close modals
        document.addEventListener('click', this.handleOutsideClick.bind(this));
        
        // Prevent form resubmission
        window.addEventListener('beforeunload', this.handleBeforeUnload.bind(this));
    }

    // Keyboard shortcuts
    handleKeyboardShortcuts(e) {
        if (e.ctrlKey || e.metaKey) {
            switch (e.key) {
                case 'k':
                    e.preventDefault();
                    this.openQuickAdd();
                    break;
                case 'd':
                    e.preventDefault();
                    this.toggleDarkMode();
                    break;
                case 's':
                    e.preventDefault();
                    this.saveCurrentForm();
                    break;
                case '/':
                    e.preventDefault();
                    this.focusSearch();
                    break;
            }
        }
        
        if (e.key === 'Escape') {
            this.closeAllModals();
        }
    }

    // Quick add transaction
    openQuickAdd() {
        const quickAddModal = document.getElementById('quick-add-modal');
        if (quickAddModal) {
            quickAddModal.classList.remove('hidden');
            const firstInput = quickAddModal.querySelector('input, select');
            if (firstInput) firstInput.focus();
        }
    }

    // Toggle dark mode
    toggleDarkMode() {
        this.isDarkMode = !this.isDarkMode;
        document.documentElement.classList.toggle('dark');
        
        // Save preference
        this.savePreference('dark_mode', this.isDarkMode ? '1' : '0');
        
        // Update charts if they exist
        this.updateChartColors();
    }

    // Save preference
    async savePreference(key, value) {
        try {
            await fetch('/ajax/save_preference.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ key, value })
            });
        } catch (error) {
            console.error('Failed to save preference:', error);
        }
    }

    // Update chart colors for dark mode
    updateChartColors() {
        if (window.chartInstances) {
            const textColor = this.isDarkMode ? '#f1f5f9' : '#374151';
            const gridColor = this.isDarkMode ? '#374151' : '#e5e7eb';
            
            Object.values(window.chartInstances).forEach(chart => {
               chart.options.plugins.legend.labels.color = textColor;
               chart.options.scales.x.ticks.color = textColor;
               chart.options.scales.x.grid.color = gridColor;
               chart.options.scales.y.ticks.color = textColor;
               chart.options.scales.y.grid.color = gridColor;
               chart.update();
           });
       }
   }

   // Focus search input
   focusSearch() {
       const searchInput = document.querySelector('input[name="search"], input[type="search"]');
       if (searchInput) {
           searchInput.focus();
           searchInput.select();
       }
   }

   // Close all modals
   closeAllModals() {
       document.querySelectorAll('.fixed.inset-0').forEach(modal => {
           if (!modal.classList.contains('hidden')) {
               modal.classList.add('hidden');
           }
       });
   }

   // Handle outside clicks
   handleOutsideClick(e) {
       const modals = document.querySelectorAll('.fixed.inset-0:not(.hidden)');
       modals.forEach(modal => {
           const modalContent = modal.querySelector('.bg-white, .bg-gray-800');
           if (modalContent && !modalContent.contains(e.target)) {
               modal.classList.add('hidden');
           }
       });
   }

   // Auto-save functionality
   initAutoSave() {
       this.autoSaveInterval = setInterval(() => {
           this.autoSaveForms();
       }, 30000); // Auto-save every 30 seconds
   }

   handleAutoSave(e) {
       const form = e.target.closest('form');
       if (form && form.id) {
           clearTimeout(this.autoSaveTimeout);
           this.autoSaveTimeout = setTimeout(() => {
               this.saveFormDraft(form);
           }, 1000);
       }
   }

   saveFormDraft(form) {
       try {
           const formData = new FormData(form);
           const draftData = {};
           
           for (let [key, value] of formData.entries()) {
               draftData[key] = value;
           }
           
           localStorage.setItem(`draft_${form.id}`, JSON.stringify(draftData));
       } catch (error) {
           console.error('Failed to save form draft:', error);
       }
   }

   loadFormDraft(formId) {
       try {
           const draft = localStorage.getItem(`draft_${formId}`);
           if (draft) {
               const draftData = JSON.parse(draft);
               const form = document.getElementById(formId);
               
               if (form) {
                   Object.keys(draftData).forEach(key => {
                       const element = form.querySelector(`[name="${key}"]`);
                       if (element) {
                           if (element.type === 'checkbox') {
                               element.checked = draftData[key] === 'on';
                           } else {
                               element.value = draftData[key];
                           }
                       }
                   });
               }
           }
       } catch (error) {
           console.error('Failed to load form draft:', error);
       }
   }

   clearFormDraft(formId) {
       localStorage.removeItem(`draft_${formId}`);
   }

   // Offline handling
   initOfflineHandler() {
       this.updateConnectionStatus();
   }

   handleOnline() {
       this.updateConnectionStatus();
       this.syncOfflineData();
       this.showNotification(this.createNotification(
           'Back Online',
           'Connection restored. Syncing data...',
           'success'
       ));
   }

   handleOffline() {
       this.updateConnectionStatus();
       this.showNotification(this.createNotification(
           'You\'re Offline',
           'Some features may be limited until connection is restored.',
           'warning'
       ));
   }

   updateConnectionStatus() {
       const statusElements = document.querySelectorAll('[data-connection-status]');
       statusElements.forEach(element => {
           element.textContent = navigator.onLine ? 'Online' : 'Offline';
           element.className = navigator.onLine ? 'text-green-600' : 'text-red-600';
       });
   }

   // Sync offline data
   async syncOfflineData() {
       try {
           const pendingData = JSON.parse(localStorage.getItem('pendingTransactions') || '[]');
           
           for (const transaction of pendingData) {
               await this.submitTransaction(transaction);
           }
           
           localStorage.removeItem('pendingTransactions');
       } catch (error) {
           console.error('Failed to sync offline data:', error);
       }
   }

   // Submit transaction (with offline support)
   async submitTransaction(data) {
       try {
           const response = await fetch('/ajax/add_transaction.php', {
               method: 'POST',
               body: data
           });
           
           if (!response.ok) {
               throw new Error('Network response was not ok');
           }
           
           return await response.json();
       } catch (error) {
           if (!navigator.onLine) {
               this.storeOfflineTransaction(data);
               throw new Error('Transaction saved offline. Will sync when connection is restored.');
           }
           throw error;
       }
   }

   storeOfflineTransaction(data) {
       const pending = JSON.parse(localStorage.getItem('pendingTransactions') || '[]');
       pending.push({
           data: data,
           timestamp: Date.now()
       });
       localStorage.setItem('pendingTransactions', JSON.stringify(pending));
   }

   // Notification system
   createNotification(title, message, type = 'info', action = null) {
       return {
           id: 'notif_' + Date.now(),
           title,
           message,
           type,
           action,
           timestamp: Date.now()
       };
   }

   showNotification(notification) {
       const container = this.getNotificationContainer();
       const element = this.createNotificationElement(notification);
       
       container.appendChild(element);
       
       // Animate in
       setTimeout(() => {
           element.classList.remove('translate-x-full');
       }, 100);
       
       // Auto-remove after 5 seconds (unless it has an action)
       if (!notification.action) {
           setTimeout(() => {
               this.removeNotification(element);
           }, 5000);
       }
   }

   getNotificationContainer() {
       let container = document.getElementById('notification-container');
       if (!container) {
           container = document.createElement('div');
           container.id = 'notification-container';
           container.className = 'fixed top-4 right-4 z-50 space-y-2';
           document.body.appendChild(container);
       }
       return container;
   }

   createNotificationElement(notification) {
       const element = document.createElement('div');
       element.className = `p-4 rounded-lg shadow-lg text-white transform translate-x-full transition-transform duration-300 max-w-sm ${this.getNotificationClass(notification.type)}`;
       
       element.innerHTML = `
           <div class="flex items-start space-x-3">
               <i class="fas fa-${this.getNotificationIcon(notification.type)} flex-shrink-0 mt-0.5"></i>
               <div class="flex-1">
                   <h4 class="font-medium">${notification.title}</h4>
                   <p class="text-sm opacity-90">${notification.message}</p>
               </div>
               <button onclick="this.parentElement.parentElement.remove()" class="text-white opacity-70 hover:opacity-100">
                   <i class="fas fa-times"></i>
               </button>
           </div>
           ${notification.action ? `
               <div class="mt-3 pt-3 border-t border-white border-opacity-20">
                   <button onclick="(${notification.action.toString()})()" class="text-sm font-medium underline">
                       ${notification.action.text || 'Action'}
                   </button>
               </div>
           ` : ''}
       `;
       
       return element;
   }

   getNotificationClass(type) {
       const classes = {
           success: 'bg-green-500',
           error: 'bg-red-500',
           warning: 'bg-yellow-500',
           info: 'bg-blue-500'
       };
       return classes[type] || classes.info;
   }

   getNotificationIcon(type) {
       const icons = {
           success: 'check-circle',
           error: 'times-circle',
           warning: 'exclamation-triangle',
           info: 'info-circle'
       };
       return icons[type] || icons.info;
   }

   removeNotification(element) {
       element.classList.add('translate-x-full');
       setTimeout(() => {
           if (element.parentNode) {
               element.parentNode.removeChild(element);
           }
       }, 300);
   }

   // Check for notifications
   async checkNotifications() {
       try {
           const response = await fetch('/ajax/get_notifications.php');
           const data = await response.json();
           
           if (data.success && data.count > 0) {
               this.updateNotificationBadges(data.count);
           }
       } catch (error) {
           console.error('Failed to check notifications:', error);
       }
   }

   updateNotificationBadges(count) {
       const badges = document.querySelectorAll('.notification-badge');
       badges.forEach(badge => {
           badge.textContent = count;
           badge.style.display = count > 0 ? 'block' : 'none';
       });
   }

   // Form validation helpers
   validateForm(form) {
       const requiredFields = form.querySelectorAll('[required]');
       let isValid = true;
       
       requiredFields.forEach(field => {
           if (!field.value.trim()) {
               this.highlightField(field, 'This field is required');
               isValid = false;
           } else {
               this.clearFieldHighlight(field);
           }
       });
       
       return isValid;
   }

   highlightField(field, message) {
       field.classList.add('border-red-500');
       
       // Remove existing error message
       const existingError = field.parentNode.querySelector('.error-message');
       if (existingError) {
           existingError.remove();
       }
       
       // Add error message
       const errorElement = document.createElement('p');
       errorElement.className = 'error-message text-red-500 text-sm mt-1';
       errorElement.textContent = message;
       field.parentNode.appendChild(errorElement);
   }

   clearFieldHighlight(field) {
       field.classList.remove('border-red-500');
       const errorMessage = field.parentNode.querySelector('.error-message');
       if (errorMessage) {
           errorMessage.remove();
       }
   }

   // Utility functions
   formatCurrency(amount, currency = 'KES') {
       return new Intl.NumberFormat('en-KE', {
           style: 'currency',
           currency: currency
       }).format(amount);
   }

   formatDate(date, format = 'short') {
       return new Intl.DateTimeFormat('en-KE', {
           dateStyle: format
       }).format(new Date(date));
   }

   debounce(func, wait) {
       let timeout;
       return function executedFunction(...args) {
           const later = () => {
               clearTimeout(timeout);
               func(...args);
           };
           clearTimeout(timeout);
           timeout = setTimeout(later, wait);
       };
   }

   // Initialize tooltips
   initTooltips() {
       const tooltipElements = document.querySelectorAll('[data-tooltip]');
       tooltipElements.forEach(element => {
           element.addEventListener('mouseenter', this.showTooltip.bind(this));
           element.addEventListener('mouseleave', this.hideTooltip.bind(this));
       });
   }

   showTooltip(e) {
       const text = e.target.getAttribute('data-tooltip');
       const tooltip = document.createElement('div');
       tooltip.className = 'absolute z-50 px-2 py-1 text-sm text-white bg-gray-900 rounded shadow-lg';
       tooltip.textContent = text;
       tooltip.id = 'tooltip';
       
       document.body.appendChild(tooltip);
       
       const rect = e.target.getBoundingClientRect();
       tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
       tooltip.style.top = rect.top - tooltip.offsetHeight - 5 + 'px';
   }

   hideTooltip() {
       const tooltip = document.getElementById('tooltip');
       if (tooltip) {
           tooltip.remove();
       }
   }

   // Handle before unload
   handleBeforeUnload(e) {
       const forms = document.querySelectorAll('form');
       let hasUnsavedChanges = false;
       
       forms.forEach(form => {
           if (form.dataset.dirty === 'true') {
               hasUnsavedChanges = true;
           }
       });
       
       if (hasUnsavedChanges) {
           e.preventDefault();
           e.returnValue = '';
       }
   }

   // Mark form as dirty
   markFormDirty(form) {
       form.dataset.dirty = 'true';
   }

   // Mark form as clean
   markFormClean(form) {
       form.dataset.dirty = 'false';
   }

   // Save current form
   saveCurrentForm() {
       const activeForm = document.querySelector('form:focus-within');
       if (activeForm) {
           const submitButton = activeForm.querySelector('button[type="submit"]');
           if (submitButton) {
               submitButton.click();
           }
       }
   }

   // Auto-save all forms
   autoSaveForms() {
       const forms = document.querySelectorAll('form[id]');
       forms.forEach(form => {
           if (form.dataset.dirty === 'true') {
               this.saveFormDraft(form);
           }
       });
   }

   // Cleanup
   destroy() {
       if (this.autoSaveInterval) {
           clearInterval(this.autoSaveInterval);
       }
       if (this.autoSaveTimeout) {
           clearTimeout(this.autoSaveTimeout);
       }
       
       document.removeEventListener('keydown', this.handleKeyboardShortcuts);
       window.removeEventListener('online', this.handleOnline);
       window.removeEventListener('offline', this.handleOffline);
       document.removeEventListener('input', this.handleAutoSave);
       document.removeEventListener('click', this.handleOutsideClick);
       window.removeEventListener('beforeunload', this.handleBeforeUnload);
   }
}

// Initialize the app when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
   window.walletApp = new WalletApp();
});

// Global utility functions
window.showNotification = (title, message, type = 'info') => {
   if (window.walletApp) {
       const notification = window.walletApp.createNotification(title, message, type);
       window.walletApp.showNotification(notification);
   }
};

window.formatMoney = (amount, currency = 'KES') => {
   if (window.walletApp) {
       return window.walletApp.formatCurrency(amount, currency);
   }
   return currency + ' ' + parseFloat(amount).toFixed(2);
};

// Chart instances storage
window.chartInstances = {};