<template>
    <div class="timeline-status-component">
        <div class="card shadow-sm" style="border-radius: 15px;">
            <post-header
                :profile="profile"
                :status="shadowStatus"
                :is-reblog="isReblog"
                :reblog-account="reblogAccount"
                @menu="openMenu"
                @follow="follow"
                @unfollow="unfollow" />

            <template v-if="!isFiltered || (isFiltered && filterType === 'blur')">
                <post-content
                    :profile="profile"
                    :status="shadowStatus"
                    :is-filtered="isFiltered"
                    :filters="filters"
                />

                <post-reactions
                	v-if="reactionBar"
                    :status="shadowStatus"
                    :profile="profile"
                    :admin="admin"
                    @like="like"
                    @unlike="unlike"
                    @share="shareStatus"
                    @unshare="unshareStatus"
                    @likes-modal="showLikes"
                    @shares-modal="showShares"
                    @toggle-comments="showComments"
                    @bookmark="handleBookmark"
                    @mod-tools="openModTools" />

                <div v-if="showCommentDrawer" class="card-footer rounded-bottom border-0" style="background: rgba(0,0,0,0.02);z-index: 3;">
                    <comment-drawer
                        :status="shadowStatus"
                        :profile="profile"
                        @handle-report="handleReport"
                        @counter-change="counterChange"
                        @show-likes="showCommentLikes"
                        @follow="follow"
                        @unfollow="unfollow" />
                </div>
            </template>

            <template v-else>
                <div class="card shadow-none mt-n2 mx-3 border-0">
                  <div class="card-body bg-warning-light p-3 ft-std">
                    <div class="badge badge-warning p-2" style="border-radius: 10px;">
                      <i class="fas fa-exclamation-triangle mr-1" aria-hidden="true"></i>
                      <span>Warning</span>
                    </div>
                    <p class="card-text mt-3" style="word-break:break-all;">
                      This post contains the following filtered keyword{{ filteredTerms?.length > 1 ? 's' : ''}}:
                      <span v-for="(term, idx) in filteredTerms" class="font-weight-bold">{{ term }}{{filteredTerms?.length === (idx + 1) ? '' : ', '}}</span>
                    </p>
                    <button class="btn btn-outline-primary font-weight-bold" @click="showHiddenStatus()" style="border-radius: 10px;">
                      Show Content
                    </button>
                  </div>
                </div>

            </template>
        </div>
    </div>
</template>

<script type="text/javascript">
    import CommentDrawer from "./post/CommentDrawer.vue";
    import PostHeader from "./post/PostHeader.vue";
    import PostContent from "./post/PostContent.vue";
    import PostReactions from "./post/PostReactions.vue";

    export default {

        components: {
            "comment-drawer": CommentDrawer,
            "post-content": PostContent,
            "post-header": PostHeader,
            "post-reactions": PostReactions
        },
        props: {
            status: {
                type: Object
            },

            profile: {
                type: Object
            },

            reactionBar: {
                type: Boolean,
                default: true
            },

            useDropdownMenu: {
                type: Boolean,
                default: false
            }
        },

        data() {
            return {
                key: 1,
                menuLoading: true,
                sensitive: false,
                showCommentDrawer: false,
                isReblogging: false,
                isBookmarking: false,
                owner: false,
                admin: false,
                license: false,
                isFiltered: false,
                filterType: undefined,
                filters: [],
                filteredTerms: []
            };
        },

        computed: {
            hideCounts: {
                get() {
                    return this.$store.state.hideCounts == true;
                }
            },

            fixedHeight: {
                get() {
                    return this.$store.state.fixedHeight == true;
                }
            },

            autoloadComments: {
                get() {
                    return this.$store.state.autoloadComments == true;
                }
            },

            newReactions: {
                get() {
                    return this.$store.state.newReactions;
                }
            },

            isReblog: {
                get() {
                    return this.status.reblog != null;
                }
            },

            reblogAccount: {
                get() {
                    return this.status.reblog ? this.status.account : null;
                }
            },

            shadowStatus: {
                get() {
                    return this.status.reblog ? this.status.reblog : this.status;
                }
            }
        },

        methods: {
            openMenu() {
                this.$emit("menu");
            },

            like() {
                this.$emit("like");
            },

            unlike() {
                this.$emit("unlike");
            },

            showLikes() {
                this.$emit("likes-modal");
            },

            showShares() {
                this.$emit("shares-modal");
            },

            showComments() {
                this.showCommentDrawer = !this.showCommentDrawer;
            },

            copyLink() {
                event.currentTarget.blur();
                App.util.clipboard(this.status.url);
            },

            shareToOther() {
                if (navigator.canShare) {
                    navigator.share({
                        url: this.status.url
                    })
                        .then(() => console.log("Share was successful."))
                        .catch((error) => console.log("Sharing failed", error));
                } else {
                    swal("Not supported", "Your current device does not support native sharing.", "error");
                }
            },

            counterChange(type) {
                this.$emit("counter-change", type);
            },

            showCommentLikes(post) {
                this.$emit("comment-likes-modal", post);
            },

            shareStatus() {
                this.$emit("share");
            },

            unshareStatus() {
                this.$emit("unshare");
            },

            handleReport(post) {
                this.$emit("handle-report", post);
            },

            follow() {
                this.$emit("follow");
            },

            unfollow() {
                this.$emit("unfollow");
            },

            handleReblog() {
                this.isReblogging = true;
                if (this.status.reblogged) {
                    this.$emit("unshare");
                } else {
                    this.$emit("share");
                }

                setTimeout(() => {
                    this.isReblogging = false;
                }, 5000);
            },

            handleBookmark() {
                event.currentTarget.blur();
                this.isBookmarking = true;
                this.$emit("bookmark");

                setTimeout(() => {
                    this.isBookmarking = false;
                }, 5000);
            },

            getStatusAvatar() {
                if (window._sharedData.user.id == this.status.account.id) {
                    return window._sharedData.user.avatar;
                }

                return this.status.account.avatar;
            },

            openModTools() {
                this.$emit("mod-tools");
            },

            applyStatusFilters() {
                const filterTypes = this.status.filtered.map(f => f.filter.filter_action);

                if (filterTypes.includes("warn")) {
                    this.applyWarnStatusFilter();
                    return;
                }
                if (filterTypes.includes("blur")) {
                    this.applyBlurStatusFilter();
                    return;
                }
            },

            applyWarnStatusFilter() {
                this.isFiltered = true;
                this.filterType = "warn";
                this.filters = this.status.filtered;
                this.filteredTerms = this.status.filtered.map(f => f.keyword_matches).flat(1);
            },

            applyBlurStatusFilter() {
                this.isFiltered = true;
                this.filterType = "blur";
                this.filters = this.status.filtered;
                this.filteredTerms = this.status.filtered.map(f => f.keyword_matches).flat(1);
            },

            showHiddenStatus() {
                this.isFiltered = false;
                this.filterType = null;
                this.filters = [];
                this.filteredTerms = [];
            }
        },

        mounted() {
            this.license = this.shadowStatus.media_attachments && this.shadowStatus.media_attachments.length ?
                this.shadowStatus
                    .media_attachments
                    .filter(m => m.hasOwnProperty("license") && m.license && m.license.hasOwnProperty("id"))
                    .map(m => m.license)[0] : false;
            this.admin = window._sharedData.user.is_admin;
            this.owner = this.shadowStatus.account.id == window._sharedData.user.id;
            if (this.shadowStatus.reply_count && this.autoloadComments && this.shadowStatus.comments_disabled === false) {
                setTimeout(() => {
                    this.showCommentDrawer = true;
                }, 1000);
            }

            if (this.status.filtered && this.status.filtered.length) {
                this.applyStatusFilters();
            }
        },

        watch: {
            status: {
                deep: true,
                immediate: true,
                handler: function(o, n) {
                    this.isBookmarking = false;
                }
            }
        }
    };
</script>

<style lang="scss">
    .timeline-status-component {
        margin-bottom: 1rem;

        .btn:focus {
            box-shadow: none !important;
        }

        .avatar {
            border-radius: 15px;
        }

        .VueCarousel-wrapper {
            .VueCarousel-slide {
                img {
                    object-fit: contain;
                }
            }
        }

        .status-text {
            z-index: 3;
            &.py-0 {
                font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
            }
        }

        .reaction-liked-by {
            font-size: 11px;
            font-weight: 600;
            font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
        }

        .timestamp,
        .visibility,
        .location {
            color: #94a3b8;
            font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
        }

        .invisible {
            display: none;
        }

        .blurhash-wrapper {
            img {
                border-radius:0;
                object-fit: cover;
            }

            canvas {
                border-radius: 0;
            }
        }

        .content-label-wrapper {
            position: relative;
            width: 100%;
            height: 400px;
            background-color: #000;
            border-radius: 0;
            overflow: hidden;

            img, canvas {
                max-height: 400px;
                cursor: pointer;
            }
        }

        .content-label {
            margin: 0;
            position: absolute;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            z-index: 2;
            border-radius: 0;
            background: rgba(0, 0, 0, 0.2)
        }

        .rounded-bottom {
            border-bottom-left-radius: 15px !important;
            border-bottom-right-radius: 15px !important;
        }

        .card-footer {
            .media {
                position: relative;

                .comment-border-link {
                    display: block;
                    position: absolute;
                    top: 40px;
                    left: 11px;
                    width: 10px;
                    height: calc(100% - 100px);
                    border-left: 4px solid transparent;
                    border-right: 4px solid transparent;
                    background-color: #E5E7EB;
                    background-clip: padding-box;

                    &:hover {
                        background-color: #BFDBFE;
                    }
                }

                .child-reply-form {
                    position: relative;
                }

                .comment-border-arrow {
                    display: block;
                    position: absolute;
                    top: -6px;
                    left: -33px;
                    width: 10px;
                    height: 29px;
                    border-left: 4px solid transparent;
                    border-right: 4px solid transparent;
                    background-color: #E5E7EB;
                    background-clip: padding-box;
                    border-bottom: 2px solid transparent;

                    &:after {
                        content: '';
                        display: block;
                        position: absolute;
                        top: 25px;
                        left: 2px;
                        width: 15px;
                        height: 2px;
                        background-color: #E5E7EB;
                    }
                }

                &-status {
                    margin-bottom: 1.3rem;
                }

                &-avatar {
                    margin-right: 12px;
                    border-radius: 8px;
                }

                &-body {
                    &-comment {
                        width: fit-content;
                        padding: 0.4rem 0.7rem;
                        background-color: var(--comment-bg);
                        border-radius: 0.9rem;

                        &-username {
                            margin-bottom: 0.25rem !important;
                            font-size: 14px;
                            font-weight: 700 !important;
                            color: var(--body-color);

                            a {
                                color: var(--body-color);
                                text-decoration: none;
                            }
                        }

                        &-content {
                            margin-bottom: 0;
                            font-size: 16px;
                        }
                    }

                    &-reactions {
                        margin-top: 0.4rem !important;
                        margin-bottom: 0 !important;
                        color: #B8C2CC !important;
                        font-size: 12px;
                    }
                }
            }
        }

        .fixedHeight {
            max-height: 400px;

            .VueCarousel-wrapper {
                border-radius: 15px;
            }

            .VueCarousel-slide {
                img {
                    max-height: 400px;
                }
            }

            .blurhash-wrapper {
                img {
                    height: 400px;
                    max-height: 400px;
                    background-color: transparent;
                    object-fit: contain;
                }

                canvas {
                    max-height: 400px;
                }
            }
            .content-label-wrapper {
                border-radius: 15px;
            }

            .content-label {
                height: 400px;
                border-radius: 0;
            }
        }
    }
</style>
