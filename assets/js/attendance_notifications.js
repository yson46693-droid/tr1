/**
 * نظام إشعارات الحضور والانصراف
 * Attendance Notifications System
 */

class AttendanceNotificationManager {
    constructor() {
        this.notificationPermission = null;
        this.reminderTimeout = null;
        this.dailyCheckInterval = null;
        this.workTime = null;
        this.userId = null;
        this.userRole = null;
    }

    /**
     * تهيئة نظام الإشعارات
     */
    async init() {
        // التحقق من أن المستخدم لديه صفحة حضور
        if (!this.hasAttendanceAccess()) {
            console.log('User does not have attendance access');
            return;
        }

        // الحصول على موعد العمل
        this.workTime = await this.getWorkTime();
        if (!this.workTime) {
            console.log('No work time found for user');
            return;
        }

        // طلب الإذن للإشعارات
        await this.requestNotificationPermission();

        // جدولة الإشعارات
        this.scheduleReminders();
        
        // فحص يومي للتأكد من جدولة الإشعارات
        this.startDailyCheck();
    }

    /**
     * التحقق من أن المستخدم لديه صفحة حضور
     */
    hasAttendanceAccess() {
        // التحقق من وجود عنصر attendance في الصفحة
        // أو من خلال role (المدير ليس له حضور)
        const userRole = document.body.getAttribute('data-user-role') || 
                        window.currentUser?.role;
        
        if (userRole === 'manager') {
            return false;
        }

        this.userRole = userRole;
        return true;
    }

    /**
     * الحصول على موعد العمل من السيرفر
     */
    async getWorkTime() {
        try {
            // الحصول على المسار الصحيح لـ API
            const apiPath = this.getApiPath('attendance.php');
            const response = await fetch(apiPath + '?action=get_work_time', {
                method: 'GET',
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error('Failed to get work time');
            }

            const data = await response.json();
            
            if (data.success && data.work_time) {
                return data.work_time;
            }

            return null;
        } catch (error) {
            console.error('Error getting work time:', error);
            // استخدام مواعيد افتراضية حسب الدور
            return this.getDefaultWorkTime();
        }
    }

    /**
     * الحصول على مواعيد افتراضية حسب الدور
     */
    getDefaultWorkTime() {
        if (this.userRole === 'accountant') {
            return {
                start: '10:00:00',
                end: '19:00:00'
            };
        } else {
            // عمال الإنتاج والمندوبين
            return {
                start: '09:00:00',
                end: '19:00:00'
            };
        }
    }

    /**
     * طلب الإذن للإشعارات
     */
    async requestNotificationPermission() {
        if (!('Notification' in window)) {
            console.warn('This browser does not support notifications');
            return false;
        }

        if (this.notificationPermission === 'granted') {
            return true;
        }

        if (this.notificationPermission === 'denied') {
            console.warn('Notification permission denied');
            return false;
        }

        // طلب الإذن
        try {
            const permission = await Notification.requestPermission();
            this.notificationPermission = permission;
            
            if (permission === 'granted') {
                console.log('Notification permission granted');
                return true;
            } else {
                console.warn('Notification permission denied or dismissed');
                return false;
            }
        } catch (error) {
            console.error('Error requesting notification permission:', error);
            return false;
        }
    }

    /**
     * جدولة إشعارات التذكير
     */
    scheduleReminders() {
        // إلغاء أي جدولة سابقة
        if (this.reminderTimeout) {
            clearTimeout(this.reminderTimeout);
            this.reminderTimeout = null;
        }

        if (!this.workTime) {
            return;
        }

        // حساب الوقت قبل 10 دقائق من موعد العمل
        const reminderTime = this.calculateReminderTime(this.workTime.start);
        
        if (!reminderTime) {
            console.log('Reminder time calculation failed or already passed');
            return;
        }

        const now = new Date();
        const timeUntilReminder = reminderTime.getTime() - now.getTime();

        if (timeUntilReminder <= 0) {
            console.log('Reminder time has already passed today');
            // جدولة للإشعار في اليوم التالي
            this.scheduleNextDayReminder();
            return;
        }

        console.log(`Scheduling reminder in ${Math.round(timeUntilReminder / 1000 / 60)} minutes`);

        // جدولة الإشعار
        this.reminderTimeout = setTimeout(() => {
            this.showReminderNotification();
            // جدولة للإشعار في اليوم التالي
            this.scheduleNextDayReminder();
        }, timeUntilReminder);
    }

    /**
     * حساب وقت التذكير (10 دقائق قبل موعد العمل)
     */
    calculateReminderTime(workStartTime) {
        const today = new Date();
        const [hours, minutes, seconds] = workStartTime.split(':').map(Number);
        
        // إنشاء كائن تاريخ لموعد العمل اليوم
        const workStart = new Date();
        workStart.setHours(hours, minutes, seconds || 0, 0);

        // حساب وقت التذكير (10 دقائق قبل)
        const reminderTime = new Date(workStart.getTime() - (10 * 60 * 1000));

        return reminderTime;
    }

    /**
     * جدولة إشعار لليوم التالي
     */
    scheduleNextDayReminder() {
        if (!this.workTime) {
            return;
        }

        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        tomorrow.setHours(0, 0, 0, 0);

        const reminderTime = this.calculateReminderTime(this.workTime.start);
        reminderTime.setDate(reminderTime.getDate() + 1);

        const now = new Date();
        const timeUntilReminder = reminderTime.getTime() - now.getTime();

        if (timeUntilReminder > 0) {
            this.reminderTimeout = setTimeout(() => {
                this.showReminderNotification();
                // إعادة جدولة لليوم التالي
                this.scheduleNextDayReminder();
            }, timeUntilReminder);
        }
    }

    /**
     * عرض إشعار التذكير
     */
    async showReminderNotification() {
        // التحقق من أن المستخدم لم يسجل حضور بعد اليوم
        const hasCheckedInToday = await this.hasCheckedInToday();
        
        if (hasCheckedInToday) {
            console.log('User has already checked in today, skipping reminder');
            return;
        }

        // التحقق من الإذن
        if (this.notificationPermission !== 'granted') {
            await this.requestNotificationPermission();
            if (this.notificationPermission !== 'granted') {
                return;
            }
        }

        const workStartFormatted = this.formatTime(this.workTime.start);
        
        const notificationOptions = {
            body: `موعد العمل يبدأ في الساعة ${workStartFormatted}. يرجى تسجيل الحضور قبل الموعد.`,
            icon: '/assets/images/logo.png', // يمكن تغييرها
            badge: '/assets/images/badge.png',
            tag: 'attendance-reminder',
            requireInteraction: false,
            silent: false,
            data: {
                url: window.location.origin + '/attendance.php',
                type: 'attendance_reminder'
            }
        };

        try {
            const notification = new Notification('تذكير بتسجيل الحضور', notificationOptions);
            
            // إضافة حدث النقر على الإشعار
            notification.onclick = function(event) {
                event.preventDefault();
                window.focus();
                if (notification.data && notification.data.url) {
                    window.open(notification.data.url, '_self');
                }
                notification.close();
            };

            // إغلاق الإشعار تلقائياً بعد 10 ثوانٍ
            setTimeout(() => {
                notification.close();
            }, 10000);

            console.log('Reminder notification shown');
        } catch (error) {
            console.error('Error showing notification:', error);
        }
    }

    /**
     * الحصول على مسار API ديناميكياً
     */
    getApiPath(filename) {
        // استخدام مسار مطلق بناءً على موقع الصفحة الحالية
        const currentPath = window.location.pathname;
        const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
        
        // إذا كنا في الجذر (مثل /v1/dashboard/production.php)، المسار سيكون /v1/api/attendance.php
        // إذا كنا في مجلد فرعي، نستخدم المسار المطلق
        
        if (pathParts.length === 0) {
            // في الجذر - استخدام مسار نسبي
            return 'api/' + filename;
        } else {
            // في مجلد فرعي - بناء مسار مطلق
            const basePath = '/' + pathParts[0];
            return basePath + '/api/' + filename;
        }
    }

    /**
     * التحقق من أن المستخدم سجل حضور اليوم
     */
    async hasCheckedInToday() {
        try {
            const apiPath = this.getApiPath('attendance.php');
            const response = await fetch(apiPath + '?action=check_today', {
                method: 'GET',
                credentials: 'same-origin'
            });

            if (!response.ok) {
                return false;
            }

            const data = await response.json();
            return data.checked_in || false;
        } catch (error) {
            console.error('Error checking today attendance:', error);
            return false;
        }
    }

    /**
     * تنسيق الوقت
     */
    formatTime(timeString) {
        const [hours, minutes] = timeString.split(':');
        const hour = parseInt(hours);
        const minute = parseInt(minutes);
        const period = hour >= 12 ? 'م' : 'ص';
        const hour12 = hour > 12 ? hour - 12 : (hour === 0 ? 12 : hour);
        return `${hour12}:${minute.toString().padStart(2, '0')} ${period}`;
    }

    /**
     * بدء الفحص اليومي
     */
    startDailyCheck() {
        // فحص كل ساعة للتأكد من جدولة الإشعارات
        this.dailyCheckInterval = setInterval(() => {
            const now = new Date();
            // إذا كان الوقت 00:00 (بداية يوم جديد)، إعادة جدولة الإشعارات
            if (now.getHours() === 0 && now.getMinutes() === 0) {
                this.scheduleReminders();
            }
        }, 60000); // فحص كل دقيقة
    }

    /**
     * إيقاف نظام الإشعارات
     */
    destroy() {
        if (this.reminderTimeout) {
            clearTimeout(this.reminderTimeout);
            this.reminderTimeout = null;
        }

        if (this.dailyCheckInterval) {
            clearInterval(this.dailyCheckInterval);
            this.dailyCheckInterval = null;
        }
    }
}

// تهيئة النظام عند تحميل الصفحة
let attendanceNotificationManager = null;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        attendanceNotificationManager = new AttendanceNotificationManager();
        attendanceNotificationManager.init();
    });
} else {
    attendanceNotificationManager = new AttendanceNotificationManager();
    attendanceNotificationManager.init();
}

// تصدير للاستخدام العام
if (typeof window !== 'undefined') {
    window.AttendanceNotificationManager = AttendanceNotificationManager;
    window.attendanceNotificationManager = attendanceNotificationManager;
}

