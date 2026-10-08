import template from './solu1-taxjar-refunds-card.html.twig';
import './solu1-taxjar-refunds-card.scss';

const { Component, Mixin, Utils } = Shopware;

Component.register('solu1-taxjar-refunds-card', {
    template,

    inject: ['acl', 'taxJarRefundApiService'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    props: {
        orderId: {
            type: String,
            required: true,
        },
        order: {
            type: Object,
            required: false,
            default: null,
        },
    },

    data() {
        return {
            report: null,
            responses: {},
            showResponseModal: false,
            isResponseLoading: false,
            responseModalContent: '',
            isLoading: false,
            isSending: false,
        };
    },

    computed: {
        isVisible() {
            return !!this.report?.returnsAvailable && this.report.returns.length > 0;
        },

        canSend() {
            return !!this.report?.canSend && this.report.pendingQuantity > 0 && this.acl.can('order.editor');
        },
    },

    watch: {
        order() {
            this.loadReport();
        },
    },

    created() {
        this.loadReport();
    },

    methods: {
        loadReport() {
            if (!this.orderId) {
                return;
            }

            this.isLoading = true;

            return this.taxJarRefundApiService.getRefunds(this.orderId).then((report) => {
                this.report = report;
            }).catch(() => {
                this.report = null;
            }).finally(() => {
                this.isLoading = false;
            });
        },

        sendRefunds() {
            this.isSending = true;

            return this.taxJarRefundApiService.sendRefunds(this.orderId).then(({ results, report }) => {
                this.report = report;

                const failed = results.filter(result => result.status === 'failed');
                if (failed.length > 0) {
                    this.createNotificationError({
                        message: this.$t('solu1-taxjar-refunds.notification.sendFailed', {
                            returns: failed.map(result => `#${result.returnNumber}`).join(', '),
                        }),
                    });

                    return;
                }

                if (results.length === 0) {
                    this.createNotificationInfo({
                        message: this.$t('solu1-taxjar-refunds.notification.nothingSent'),
                    });

                    return;
                }

                this.createNotificationSuccess({
                    message: this.$t('solu1-taxjar-refunds.notification.sendSuccess'),
                });
            }).catch((error) => {
                this.createNotificationError({
                    message: error?.response?.data?.message || this.$t('solu1-taxjar-refunds.notification.sendError'),
                });
            }).finally(() => {
                this.isSending = false;
            });
        },

        openResponseModal(item) {
            this.responseModalContent = '';
            this.isResponseLoading = true;
            this.showResponseModal = true;

            Promise.all(item.transactionIds.map(transactionId => this.loadResponse(transactionId))).then(() => {
                const responses = Object.fromEntries(item.transactionIds.map((transactionId) => {
                    return [transactionId, this.responses[transactionId] ?? this.$t('solu1-taxjar-refunds.card.responseUnavailable')];
                }));

                this.responseModalContent = JSON.stringify(
                    item.transactionIds.length === 1 ? Object.values(responses)[0] : responses,
                    null,
                    2,
                );
            }).finally(() => {
                this.isResponseLoading = false;
            });
        },

        loadResponse(transactionId) {
            if (transactionId in this.responses) {
                return Promise.resolve();
            }

            return this.taxJarRefundApiService.getRefundResponse(this.orderId, transactionId).then(({ response }) => {
                this.responses = { ...this.responses, [transactionId]: response };
            }).catch(() => {
                this.responses = { ...this.responses, [transactionId]: null };
            });
        },

        copyResponse() {
            Utils.dom.copyStringToClipboard(this.responseModalContent).then(() => {
                this.createNotificationSuccess({
                    message: this.$t('solu1-taxjar-refunds.card.copySuccess'),
                });
            });
        },

        statusVariant(status) {
            return { sent: 'success', partial: 'warning', pending: 'danger' }[status];
        },

        formatDate(value) {
            return value ? Utils.format.date(value) : '';
        },
    },
});
