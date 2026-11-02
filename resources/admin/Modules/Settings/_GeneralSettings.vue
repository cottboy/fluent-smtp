<template>
    <!--
        Sidebar settings, so the switch sits beside the name it belongs to and the
        sentence explaining it hangs underneath.

        The label-left/control-right rows this used to be need a wide column to be worth
        it - pinning a switch to the right edge of a 312px card only puts it a finger's
        width from its own label, with the explanation wrapping over four lines to get
        there. The one control that is not a switch takes the full width instead.
    -->
    <div class="fss_general_settings">
        <div class="fsm_toggle">
            <div class="fsm_toggle_main">
                <el-switch v-model="settings.misc.log_emails" :aria-label="$t('Log Emails')"
                           active-value="yes" inactive-value="no"/>
                <span class="fsm_toggle_title" @click="toggle('log_emails')">{{ $t('Log Emails') }}</span>
            </div>
            <div class="fsm_toggle_body">
                <p>{{ $t('Keep a record of every email this site sends, so you can review, search and resend it.') }}</p>
            </div>
        </div>

        <div class="fsm_toggle" v-if="settings.misc.log_emails == 'yes' && !!appVars.has_fluentcrm">
            <div class="fsm_toggle_main">
                <el-switch v-model="settings.misc.disable_fluentcrm_logs"
                           :aria-label="$t('Exclude FluentCRM Emails')"
                           active-value="yes" inactive-value="no"/>
                <span class="fsm_toggle_title" @click="toggle('disable_fluentcrm_logs')">
                    {{ $t('Exclude FluentCRM Emails') }}
                </span>
            </div>
            <div class="fsm_toggle_body">
                <p>{{ $t('Keep campaign and automation emails sent by FluentCRM out of this log. FluentCRM keeps its own record of them.') }}</p>
            </div>
        </div>

        <div class="fsm_row fsm_row_stacked" v-if="settings.misc.log_emails == 'yes'">
            <div class="fsm_row_label">
                <span class="fsm_row_title">{{ $t('Delete Logs') }}</span>
                <p>{{ $t('How long a logged email is kept before FluentSMTP deletes it automatically.') }}</p>
            </div>
            <div class="fsm_row_control">
                <el-select v-model="settings.misc.log_saved_interval_days"
                           :aria-label="$t('Delete Logs')">
                    <el-option
                        v-for="(logLabel, logValue) in logging_days"
                        :key="logValue"
                        :value="logValue"
                        :label="logLabel"
                    ></el-option>
                </el-select>
            </div>
        </div>

        <div class="fsm_toggle" v-if="settings.misc.log_emails == 'yes'">
            <div class="fsm_toggle_main">
                <el-switch v-model="settings.misc.async_email_sending"
                           :aria-label="$t('Async Email Sending')"
                           active-value="yes" inactive-value="no"/>
                <span class="fsm_toggle_title" @click="toggle('async_email_sending')">
                    {{ $t('Async Email Sending') }}
                </span>
            </div>
            <div class="fsm_toggle_body">
                <p>{{ $t('Queue emails and send them in the background on the next scheduled run. Test emails and manual resends are sent immediately.') }}</p>
                <p v-if="settings.misc.async_email_sending == 'yes' && appVars.wp_cron_disabled"
                   class="fsm_row_warning">
                    {{ $t('WP-Cron is disabled on this site, so queued emails will not be sent until a real cron job runs wp-cron.php.') }}
                </p>
            </div>
        </div>

        <div class="fsm_toggle" v-if="settings.misc.async_email_sending == 'yes' && settings.misc.log_emails == 'yes'">
            <div class="fsm_toggle_main">
                <el-switch v-model="settings.misc.email_rate_limit"
                           :aria-label="$t('Email Rate Limit')"
                           active-value="yes" inactive-value="no"/>
                <span class="fsm_toggle_title" @click="toggle('email_rate_limit')">
                    {{ $t('Email Rate Limit') }}
                </span>
            </div>
            <div class="fsm_toggle_body">
                <p>{{ $t('Pause the background queue when any of these windows is full. The queue continues when the window has room again.') }}</p>

                <div class="fsm_rate_grid" v-if="settings.misc.email_rate_limit == 'yes'">
                    <label v-for="field in visible_rate_fields" :key="field.key" class="fsm_rate_field">
                        <span>{{ field.label }}</span>
                        <el-input v-model="settings.misc[field.key]" type="number" min="0" step="1"
                                  :aria-label="field.label"/>
                    </label>
                </div>

                <p class="fsm_rate_hint" v-if="settings.misc.email_rate_limit == 'yes'">
                    {{ $t('0 means no limit.') }}
                </p>
            </div>
        </div>

        <div class="fsm_toggle" v-if="settings.misc.async_email_sending == 'yes' && settings.misc.log_emails == 'yes'">
            <div class="fsm_toggle_main">
                <el-switch v-model="settings.misc.retry_failed_emails"
                           :aria-label="$t('Retry Failed Emails')"
                           active-value="yes" inactive-value="no"/>
                <span class="fsm_toggle_title" @click="toggle('retry_failed_emails')">
                    {{ $t('Retry Failed Emails') }}
                </span>
            </div>
            <div class="fsm_toggle_body">
                <p>{{ $t('Retry a background email that failed after 2, 20 and 60 minutes. A failure after the third retry is final. Test emails and manual resends are never retried automatically.') }}</p>
            </div>
        </div>

        <div class="fsm_toggle" v-if="settings.misc.log_emails == 'yes'">
            <div class="fsm_toggle_main">
                <el-switch v-model="settings.misc.keep_email_attachments"
                           :aria-label="$t('Keep Attachments in Logs')"
                           active-value="yes" inactive-value="no"/>
                <span class="fsm_toggle_title" @click="toggle('keep_email_attachments')">
                    {{ $t('Keep Attachments in Logs') }}
                </span>
            </div>
            <div class="fsm_toggle_body">
                <p>{{ $t('Save a copy of each attachment with the log so the email can be resent after the original file is gone. Copies are deleted with the log.') }}</p>
            </div>
        </div>

        <div class="fsm_toggle">
            <div class="fsm_toggle_main">
                <el-switch v-model="settings.misc.simulate_emails" :aria-label="$t('Email Simulation')"
                           active-value="yes" inactive-value="no"/>
                <span class="fsm_toggle_title" @click="toggle('simulate_emails')">{{ $t('Email Simulation') }}</span>
            </div>
            <div class="fsm_toggle_body">
                <p>{{ $t('__Email_Simulation_Label') }}</p>
                <p class="fsm_row_warning" v-if="settings.misc.simulate_emails == 'yes'">
                    {{ $t('__Email_Simulation_Yes') }}
                </p>
                <p class="fsm_row_warning" v-if="appVars.is_disabled_defined">
                    {{ $t('Simulation is turned on by ') }}
                    <b>FLUENTMAIL_SIMULATE_EMAILS</b>{{ $t(' in your PHP code, so this switch has no effect.') }}
                </p>
            </div>
        </div>

        <div class="fsm_toggle">
            <div class="fsm_toggle_main">
                <el-switch v-model="settings.misc.send_as_text"
                           :aria-label="$t('Plain Text Alternative (beta)')"
                           active-value="yes" inactive-value="no"/>
                <span class="fsm_toggle_title" @click="toggle('send_as_text')">
                    {{ $t('Plain Text Alternative (beta)') }}
                </span>
            </div>
            <div class="fsm_toggle_body">
                <p>{{ $t('__Email_TEXT_PART_Label') }}</p>
            </div>
        </div>

        <div class="fsm_row_actions">
            <el-button v-loading="saving" @click="saveMiscSettings()" type="primary">
                {{ $t('Save Settings') }}
            </el-button>
        </div>
    </div>
</template>

<script type="text/babel">
    export default {
        name: 'FluentMailGeneralSettings',
        data() {
            return {
                saving: false,
                logging_days: {
                    7: this.$t('After 7 Days'),
                    14: this.$t('After 14 Days'),
                    30: this.$t('After 30 Days'),
                    60: this.$t('After 60 Days'),
                    90: this.$t('After 90 Days'),
                    180: this.$t('After 6 Months'),
                    365: this.$t('After 1 Year'),
                    730: this.$t('After 2 Years')
                },
                rate_fields: [
                    {key: 'rate_limit_per_minute', label: this.$t('Per Minute'), seconds: 60},
                    {key: 'rate_limit_per_hour', label: this.$t('Per Hour'), seconds: 3600},
                    {key: 'rate_limit_per_day', label: this.$t('Per Day'), seconds: 86400},
                    {key: 'rate_limit_per_week', label: this.$t('Per Week'), seconds: 604800},
                    {key: 'rate_limit_per_month', label: this.$t('Per Month'), seconds: 2592000}
                ]
            }
        },
        computed: {
            /*
             * Log rows older than the retention period are deleted, so a window
             * longer than that period can never count a full window. Hiding it
             * keeps the form from offering a limit the site cannot measure.
             * A retention of 0 means logs are never deleted, so every window fits.
             */
            logRetentionSeconds() {
                const days = parseInt(this.settings.misc.log_saved_interval_days, 10);
                return isFinite(days) && days > 0 ? days * 86400 : Infinity;
            },
            visible_rate_fields() {
                return this.rate_fields.filter(field => field.seconds <= this.logRetentionSeconds);
            }
        },
        methods: {
            /*
             * The title beside a switch flips it, the way a <label for> would - which
             * this deliberately is not. Element Plus puts its click handler on the
             * switch's outer element and its change handler on the hidden checkbox
             * inside it, so a label's forwarded click would run both and land back on
             * the value it started from. The switch keeps the name on `aria-label`.
             */
            toggle(key) {
                this.settings.misc[key] = this.settings.misc[key] === 'yes' ? 'no' : 'yes';
            },

            /*
             * Default and Fallback are not on this form any more - they are set from the
             * connection rows beside it, which is where you can see which connection you
             * are choosing. They are still posted, because misc-settings replaces the
             * whole object; they are just not editable here, so the two-selects-agree
             * check this used to run has nothing left to catch.
             */
            saveMiscSettings() {
                this.saving = true;

                /*
                 * The backend enforces the same dependencies. Doing it here keeps
                 * the switches on screen matching what was actually stored after
                 * the response, instead of showing async as on until a reload.
                 */
                if (this.settings.misc.log_emails != 'yes') {
                    this.settings.misc.async_email_sending = 'no';
                }

                if (this.settings.misc.async_email_sending != 'yes') {
                    this.settings.misc.email_rate_limit = 'no';
                    this.settings.misc.retry_failed_emails = 'no';
                }

                this.rate_fields.forEach(field => {
                    const value = parseInt(this.settings.misc[field.key], 10);
                    this.settings.misc[field.key] = String(
                        isFinite(value) && value > 0 ? Math.min(value, 1000000) : 0
                    );
                });

                this.$post('misc-settings', {
                    settings: this.settings.misc
                })
                    .then(response => {
                        this.$notify.success(response.data.message);
                    })
                    .fail((error) => {
                        /*
                         * The form is not reset here on purpose. The value the user
                         * typed is still the value they want; telling them it did not
                         * save lets them retry without typing it again. What must not
                         * happen is silence, which reads as success until the next
                         * reload puts the old value back.
                         */
                        this.$notify.error(this.$errorMessage(error));
                    })
                    .always(() => {
                        this.saving = false;
                    });
            }
        }
    };
</script>

<style lang="scss">
.fss_general_settings {
    /* The sidebar is one column wide, so a label above its control is the only shape
     * that fits - the select takes the width rather than being pinned right. */
    .fsm_row.fsm_row_stacked {
        .fsm_row_label p {
            margin-bottom: 8px;
        }
    }

    /* Only shown when the setting is not what it ought to be, so it reads as a flag. */
    .fsm_row_warning {
        font-size: 12px;
        color: var(--fsm-warning-fg) !important;
        margin-top: 6px !important;
    }

    .fsm_rate_grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        margin-top: 10px;
    }

    .fsm_rate_field {
        display: flex;
        flex-direction: column;
        gap: 4px;
        font-size: 12px;
        color: var(--fsm-text-mid);
    }

    .fsm_rate_hint {
        font-size: 12px;
        color: var(--fsm-text-light);
        margin-top: 6px !important;
    }

    /* 12 here and 4 from .fsm_card_body is the 16 the card head is padded by. */
    .fsm_row_actions {
        padding-top: 16px;
        padding-bottom: 12px;

        .el-button {
            width: 100%;
        }
    }
}
</style>
